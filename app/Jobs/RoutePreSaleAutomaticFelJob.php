<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\Business;
use App\Models\FiscalDocumentIntent;
use App\Models\PreSale;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\Sale;
use App\Models\User;
use App\Services\Routes\RoutePreSaleFelAvailabilityService;
use App\Services\Routes\RoutePreSaleFelEligibilityService;
use App\Services\Routes\RoutePreSaleFelService;
use App\Services\Fiscal\FiscalDocumentIntentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RoutePreSaleAutomaticFelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(
        public int $preSaleId,
        public int $saleId,
        public int $businessId,
        public int $branchId,
        public int $userId,
    )
    {
        $this->onQueue((string) config('fel.route_automation_queue', 'fel'));
    }

    public function handle(
        RoutePreSaleFelService $fel,
        RoutePreSaleFelEligibilityService $eligibility,
        RoutePreSaleFelAvailabilityService $availability,
        FiscalDocumentIntentService $intents,
    ): void
    {
        if (! config('fel.route_automation_enabled')) {
            return;
        }

        $preSale = PreSale::query()
            ->where('business_id', $this->businessId)
            ->where('branch_id', $this->branchId)
            ->whereKey($this->preSaleId)
            ->with('customer')
            ->first();
        $user = User::query()->find($this->userId);
        $sale = Sale::query()
            ->where('business_id', $this->businessId)
            ->where('branch_id', $this->branchId)
            ->whereKey($this->saleId)
            ->with('electronicDocument')
            ->first();

        if (! $preSale || ! $sale || ! $user || (int) $user->business_id !== $this->businessId || (int) $preSale->converted_sale_id !== $sale->id || $preSale->status !== PreSale::STATUS_CONVERTED || $sale->status === 'cancelled') {
            return;
        }

        $intent = $this->intentFor($sale);
        if ($intent) {
            if ($intent->status === FiscalDocumentIntent::CERTIFIED) {
                $intent = $intents->finalize($intent);
            }

            if ($this->syncIntentStatus($preSale, $sale, $intent)) {
                return;
            }
        } elseif (in_array($sale->electronicDocument?->status ?? $sale->certification_status, ['certified', 'unknown', 'failed', 'pending'], true)) {
            // Compatibility for historical sales that predate durable intents.
            $legacyStatus = $sale->electronicDocument?->status ?? $sale->certification_status;
            $this->updateEntry($preSale, $sale, match ($legacyStatus) {
                'certified' => 'certified',
                'unknown' => 'unknown',
                'pending' => 'queued',
                default => 'failed',
            }, $sale->electronicDocument?->error_message);

            return;
        }

        $eligibilityResult = $eligibility->persist($preSale);
        if (! $eligibilityResult['eligible']) {
            $this->updateEntry($preSale, $sale, 'not_requested', $eligibilityResult['reason']);
            Log::info('route_fel_auto.skipped_ineligible', [
                ...$this->context($preSale, $sale),
                'reason_code' => $eligibilityResult['reason_code'],
            ]);

            return;
        }

        $business = Business::query()->find($this->businessId);
        $branch = Branch::query()->where('business_id', $this->businessId)->find($this->branchId);
        $availabilityResult = $business && $branch ? $availability->evaluate($business, $branch) : [
            'available' => false,
            'reason_code' => 'unknown',
            'reason' => 'FEL no configurado para certificación automática.',
        ];

        if (! $availabilityResult['available']) {
            $this->updateEntry($preSale, $sale, 'not_requested', 'FEL no configurado para certificación automática.');
            Log::info('route_fel_auto.skipped_unavailable', [
                ...$this->context($preSale, $sale),
                'reason_code' => $availabilityResult['reason_code'],
            ]);

            return;
        }

        try {
            $fel->certify($preSale, $user, "route-auto-fel-{$preSale->id}");
            $intent = $this->intentFor($sale);
            if ($intent) {
                $this->syncIntentStatus($preSale, $sale, $intent);
                Log::info("route_fel_auto.{$intent->status}", $this->context($preSale, $sale));
            }
        } catch (Throwable $exception) {
            $intent = $this->intentFor($sale);
            $status = $intent ? $this->dispatchStatus($intent) : 'failed';
            $message = $intent?->last_reconciliation_error
                ?: $intent?->last_finalization_error
                ?: 'No se pudo certificar FEL automáticamente.';
            $this->updateEntry($preSale, $sale, $status, $message);
            Log::warning("route_fel_auto.{$status}", [
                ...$this->context($preSale, $sale),
                'error_class' => $exception::class,
            ]);
        }
    }

    private function intentFor(Sale $sale): ?FiscalDocumentIntent
    {
        return FiscalDocumentIntent::query()
            ->where('business_id', $sale->business_id)
            ->where('finalized_sale_id', $sale->id)
            ->where('operation_type', 'certify_existing_sale')
            ->latest('id')
            ->first();
    }

    /** Returns true when the intent requires no provider call from this job. */
    private function syncIntentStatus(PreSale $preSale, Sale $sale, FiscalDocumentIntent $intent): bool
    {
        $status = $this->dispatchStatus($intent);
        $this->updateEntry($preSale, $sale, $status, $intent->last_reconciliation_error ?: $intent->last_finalization_error);

        return in_array($intent->status, [
            FiscalDocumentIntent::PENDING,
            FiscalDocumentIntent::UNKNOWN,
            FiscalDocumentIntent::CERTIFIED,
            FiscalDocumentIntent::FINALIZED,
            FiscalDocumentIntent::REJECTED,
            FiscalDocumentIntent::RETRYABLE,
        ], true);
    }

    private function dispatchStatus(FiscalDocumentIntent $intent): string
    {
        return match ($intent->status) {
            FiscalDocumentIntent::FINALIZED, FiscalDocumentIntent::CERTIFIED => 'certified',
            FiscalDocumentIntent::UNKNOWN => 'unknown',
            FiscalDocumentIntent::PENDING => 'queued',
            // The legacy delivery-batch contract has no rejected/retryable values.
            FiscalDocumentIntent::REJECTED, FiscalDocumentIntent::RETRYABLE => 'failed',
            default => 'failed',
        };
    }

    private function updateEntry(PreSale $preSale, Sale $sale, string $status, ?string $errorMessage = null): void
    {
        RouteDeliveryBatchPreSale::query()
            ->where('pre_sale_id', $preSale->id)
            ->where('sale_id', $sale->id)
            ->update(['fel_dispatch_status' => $status, 'error_message' => $errorMessage]);
    }

    private function context(PreSale $preSale, Sale $sale): array
    {
        return [
            'business_id' => $this->businessId,
            'branch_id' => $this->branchId,
            'pre_sale_id' => $preSale->id,
            'sale_id' => $sale->id,
        ];
    }
}
