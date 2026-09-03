<?php

namespace App\Services\Routes;

use App\Jobs\RoutePreSaleAutomaticFelJob;
use App\Models\OperationIdempotencyKey;
use App\Models\PreSale;
use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteWorkDay;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteDeliveryBatchService
{
    private const PAYMENT_METHODS = ['cash', 'card', 'transfer', 'check'];

    public function __construct(
        private readonly RoutePreSaleReceiptService $receipts,
        private readonly RoutePreSaleFelEligibilityService $eligibility,
        private readonly RouteCashOperationGuard $cash,
    ) {
    }

    public function deliverAll(RouteWorkDay $workDay, User $user, string $idempotencyKey): IdempotencyResult
    {
        $businessId = (int) $workDay->business_id;
        $branchId = (int) $workDay->branch_id;
        $snapshot = $this->snapshot($workDay, $user, $idempotencyKey);

        return app(IdempotencyService::class)->run(
            $businessId,
            $branchId,
            $user->id,
            'route_deliver_all',
            $idempotencyKey,
            ['business_id' => $businessId, 'branch_id' => $branchId, 'route_work_day_id' => $workDay->id, 'pre_sales' => $snapshot],
            function () use ($workDay, $user, $businessId, $branchId, $idempotencyKey) {
                return DB::transaction(function () use ($workDay, $user, $businessId, $branchId, $idempotencyKey) {
                    $lockedWorkDay = RouteWorkDay::query()
                        ->where('business_id', $businessId)
                        ->where('branch_id', $branchId)
                        ->whereKey($workDay->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    abort_unless((int) BranchInventory::activeBranch($businessId)->id === $branchId, 403);
                    $this->cash->requireOpen($businessId, $branchId, true);

                    $settings = TenantSetting::query()->where('business_id', $businessId)->first();
                    $timing = $settings?->route_pre_sale_stock_deduction_timing === 'picking' ? 'picking' : 'invoice';
                    $mode = in_array($settings?->route_pre_sale_invoicing_mode, ['automatic', 'automatic_all'], true) ? 'automatic_all' : 'manual';
                    $automationEnabled = $mode === 'automatic_all' && (bool) config('fel.route_automation_enabled');

                    $preSales = PreSale::query()
                        ->where('business_id', $businessId)
                        ->where('branch_id', $branchId)
                        ->where('route_work_day_id', $lockedWorkDay->id)
                        ->where('status', PreSale::STATUS_PICKED)
                        ->whereNull('converted_sale_id')
                        ->with('customer')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($preSales->isEmpty()) {
                        throw ValidationException::withMessages(['pre_sales' => 'No hay preventas preparadas disponibles para entregar en esta jornada.']);
                    }

                    $eligibilityByPreSale = [];
                    foreach ($preSales as $preSale) {
                        if (! in_array($preSale->payment_method, self::PAYMENT_METHODS, true)) {
                            throw ValidationException::withMessages(['payment_method' => 'Cada preventa preparada debe tener una forma de pago antes de entregar.']);
                        }

                        $eligibility = $this->eligibility->persist($preSale);
                        if ((bool) ($settings?->route_pre_sale_require_fel_eligible_customer ?? false) && ! $eligibility['eligible']) {
                            throw ValidationException::withMessages(['pre_sale' => $eligibility['reason']]);
                        }
                        $eligibilityByPreSale[$preSale->id] = $eligibility;
                    }

                    $batch = RouteDeliveryBatch::query()->create([
                        'business_id' => $businessId,
                        'branch_id' => $branchId,
                        'route_work_day_id' => $lockedWorkDay->id,
                        'route_zone_id' => $lockedWorkDay->route_zone_id,
                        'delivered_by' => $user->id,
                        'status' => RouteDeliveryBatch::STATUS_PROCESSING,
                        'stock_deduction_timing' => $timing,
                        'invoicing_mode' => $mode,
                        'fel_automation_enabled' => $automationEnabled,
                    ]);

                    $totalItems = 0;
                    $totalAmount = 0.0;
                    $automaticFelPreSales = [];

                    foreach ($preSales as $preSale) {
                        $receipt = $this->receipts->convertToInternalReceipt($preSale, [
                            'idempotency_key' => 'route-delivery-'.hash('sha256', "{$batch->id}:{$preSale->id}:{$idempotencyKey}"),
                            'payment_condition' => 'paid',
                            'payment_method' => $preSale->payment_method,
                            'note' => "Entrega de ruta #{$batch->id}",
                        ], $user);
                        $sale = $preSale->refresh()->convertedSale()->withCount('items')->firstOrFail();
                        $eligibleForAutomaticFel = $automationEnabled && (bool) $eligibilityByPreSale[$preSale->id]['eligible'];

                        RouteDeliveryBatchPreSale::query()->create([
                            'route_delivery_batch_id' => $batch->id,
                            'pre_sale_id' => $preSale->id,
                            'sale_id' => $sale->id,
                            'status' => 'delivered',
                            'payment_method' => $preSale->payment_method,
                            'fel_dispatch_status' => $eligibleForAutomaticFel ? 'queued' : 'not_requested',
                        ]);

                        $totalItems += $sale->items_count;
                        $totalAmount += (float) $sale->total;
                        if ($eligibleForAutomaticFel) {
                            $automaticFelPreSales[] = $preSale->id;
                        }
                    }

                    $batch->update([
                        'status' => RouteDeliveryBatch::STATUS_COMPLETED,
                        'delivered_at' => now(),
                        'total_pre_sales' => $preSales->count(),
                        'total_items' => $totalItems,
                        'total_amount' => round($totalAmount, 2),
                    ]);

                    if ($automaticFelPreSales !== []) {
                        DB::afterCommit(function () use ($automaticFelPreSales, $user) {
                            foreach ($automaticFelPreSales as $preSaleId) {
                                RoutePreSaleAutomaticFelJob::dispatch($preSaleId, $user->id)
                                    ->onQueue((string) config('fel.route_automation_queue', 'fel'));
                            }
                        });
                    }

                    return ['result_id' => $batch->id, 'response_payload' => ['batch_id' => $batch->id, 'total_pre_sales' => $preSales->count()]];
                });
            },
            'route_delivery_batch',
        );
    }

    private function snapshot(RouteWorkDay $workDay, User $user, string $idempotencyKey): array
    {
        $existing = OperationIdempotencyKey::query()
            ->where('business_id', $workDay->business_id)->where('branch_id', $workDay->branch_id)
            ->where('user_id', $user->id)->where('operation_type', 'route_deliver_all')
            ->where('idempotency_key', $idempotencyKey)->where('status', IdempotencyService::STATUS_COMPLETED)
            ->value('result_id');

        if ($existing) {
            return RouteDeliveryBatchPreSale::query()->where('route_delivery_batch_id', $existing)->orderBy('pre_sale_id')->pluck('pre_sale_id')->all();
        }

        return PreSale::query()->where('business_id', $workDay->business_id)->where('branch_id', $workDay->branch_id)
            ->where('route_work_day_id', $workDay->id)->where('status', PreSale::STATUS_PICKED)->whereNull('converted_sale_id')->orderBy('id')->pluck('id')->all();
    }
}
