<?php

namespace App\Services\Routes;

use App\Jobs\RoutePreSaleAutomaticFelJob;
use App\Models\OperationIdempotencyKey;
use App\Models\Branch;
use App\Models\Business;
use App\Models\PreSale;
use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteWorkDay;
use App\Models\RoutePreSaleCollection;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RouteDeliveryBatchService
{
    private const PAYMENT_METHODS = ['cash', 'card', 'transfer', 'check'];

    public function __construct(
        private readonly RoutePreSaleReceiptService $receipts,
        private readonly RoutePreSaleFelEligibilityService $eligibility,
        private readonly RoutePreSaleFelAvailabilityService $availability,
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
                    $collectionResponsibility = $settings?->route_collection_responsibility === 'delivery_agent' ? 'delivery_agent' : 'pre_seller';
                    $deliveryTracking = $settings?->route_delivery_tracking === 'in_app' ? 'in_app' : 'external';
                    $timing = $settings?->route_pre_sale_stock_deduction_timing === 'picking' ? 'picking' : 'invoice';
                    $mode = in_array($settings?->route_pre_sale_invoicing_mode, ['automatic', 'automatic_all'], true) ? 'automatic_all' : 'manual';
                    $automationEnabled = $mode === 'automatic_all' && (bool) config('fel.route_automation_enabled');
                    $felAvailability = $automationEnabled
                        ? $this->availability->evaluate(
                            Business::query()->findOrFail($businessId),
                            Branch::query()->where('business_id', $businessId)->findOrFail($branchId),
                        )
                        : null;

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
                        if (! in_array($preSale->agreed_payment_method, self::PAYMENT_METHODS, true)) {
                            throw ValidationException::withMessages(['agreed_payment_method' => 'Cada preventa preparada debe tener un método de pago acordado antes de entregar.']);
                        }
                        if ($collectionResponsibility === 'pre_seller' && ! RoutePreSaleCollection::query()->where('business_id', $businessId)->where('branch_id', $branchId)->where('pre_sale_id', $preSale->id)->whereIn('status', ['captured', 'linked'])->lockForUpdate()->exists()) {
                            throw ValidationException::withMessages(['collection' => 'Debe registrar el cobro antes de generar el comprobante.']);
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
                        'delivery_tracking_snapshot' => $deliveryTracking,
                        'collection_responsibility_snapshot' => $collectionResponsibility,
                        'operation_settings_snapshotted_at' => now(),
                    ]);

                    $totalItems = 0;
                    $totalAmount = 0.0;
                    $automaticFelSales = [];

                    foreach ($preSales as $preSale) {
                        $collection = $collectionResponsibility === 'pre_seller'
                            ? RoutePreSaleCollection::query()->where('business_id', $businessId)->where('branch_id', $branchId)->where('pre_sale_id', $preSale->id)->where('status', 'captured')->lockForUpdate()->firstOrFail()
                            : null;
                        if ($collection && round((float) $collection->amount, 2) !== round((float) $preSale->total, 2)) {
                            throw ValidationException::withMessages(['collection' => 'El cobro debe coincidir con el total final de la preventa.']);
                        }
                        $receipt = $this->receipts->convertToInternalReceipt($preSale, [
                            'idempotency_key' => 'route-delivery-'.hash('sha256', "{$batch->id}:{$preSale->id}:{$idempotencyKey}"),
                            'payment_condition' => $collectionResponsibility === 'delivery_agent' ? 'unpaid' : 'paid',
                            'payment_method' => $collection?->payment_method,
                            'skip_payment_posting' => $collectionResponsibility === 'pre_seller',
                            'note' => "Entrega de ruta #{$batch->id}",
                        ], $user);
                        $sale = $preSale->refresh()->convertedSale()->withCount('items')->firstOrFail();
                        if ($collection) {
                            $sale->payments()->firstOrCreate(
                                ['route_pre_sale_collection_id' => $collection->id],
                                ['business_id' => $businessId, 'method' => $collection->payment_method, 'amount' => $sale->total, 'reference' => $collection->reference, 'collected_by' => $collection->collected_by, 'collected_at' => $collection->collected_at, 'cash_register_session_id' => $collection->cash_register_session_id],
                            );
                            $collection->update(['status' => 'linked']);
                        }
                        $automaticFelReason = null;
                        $eligibleForAutomaticFel = $automationEnabled && (bool) $eligibilityByPreSale[$preSale->id]['eligible'] && (bool) ($felAvailability['available'] ?? false);

                        if ($automationEnabled && ! $eligibilityByPreSale[$preSale->id]['eligible']) {
                            $automaticFelReason = $eligibilityByPreSale[$preSale->id]['reason'];
                            Log::info('route_fel_auto.skipped_ineligible', [
                                'business_id' => $businessId,
                                'branch_id' => $branchId,
                                'pre_sale_id' => $preSale->id,
                                'reason_code' => $eligibilityByPreSale[$preSale->id]['reason_code'],
                            ]);
                        }

                        if ($automationEnabled && $eligibilityByPreSale[$preSale->id]['eligible'] && ! ($felAvailability['available'] ?? false)) {
                            $automaticFelReason = 'FEL no configurado para certificación automática.';
                            Log::info('route_fel_auto.skipped_unavailable', [
                                'business_id' => $businessId,
                                'branch_id' => $branchId,
                                'pre_sale_id' => $preSale->id,
                                'reason_code' => $felAvailability['reason_code'],
                            ]);
                        }

                        RouteDeliveryBatchPreSale::query()->create([
                            'route_delivery_batch_id' => $batch->id,
                            'pre_sale_id' => $preSale->id,
                            'sale_id' => $sale->id,
                            'status' => 'delivered',
                            'payment_method' => $collection?->payment_method ?? $preSale->agreed_payment_method,
                            'fel_dispatch_status' => $eligibleForAutomaticFel ? 'queued' : 'not_requested',
                            'error_message' => $automaticFelReason,
                        ]);

                        $totalItems += $sale->items_count;
                        $totalAmount += (float) $sale->total;
                        if ($eligibleForAutomaticFel) {
                            $automaticFelSales[$preSale->id] = $sale->id;
                        }
                    }

                    $batch->update([
                        'status' => RouteDeliveryBatch::STATUS_COMPLETED,
                        'delivered_at' => now(),
                        'total_pre_sales' => $preSales->count(),
                        'total_items' => $totalItems,
                        'total_amount' => round($totalAmount, 2),
                    ]);

                    if ($automaticFelSales !== []) {
                        DB::afterCommit(function () use ($automaticFelSales, $user, $businessId, $branchId) {
                            foreach ($automaticFelSales as $preSaleId => $saleId) {
                                RoutePreSaleAutomaticFelJob::dispatch($preSaleId, $saleId, $businessId, $branchId, $user->id)
                                    ->onQueue((string) config('fel.route_automation_queue', 'fel'));
                                Log::info('route_fel_auto.dispatched', [
                                    'business_id' => $businessId,
                                    'branch_id' => $branchId,
                                    'pre_sale_id' => $preSaleId,
                                ]);
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
