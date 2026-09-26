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
use App\Models\SalePayment;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\CashRegister;
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
        private readonly RouteBranchCollectionSettingsService $branchCollectionSettings,
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
                    $branchPolicy = $this->branchCollectionSettings->lockValidatedPolicyForExecution($businessId, $branchId);

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

                    $policyAware = $branchPolicy !== null;
                    $workflow = $branchPolicy['collection_workflow_mode'] ?? null;
                    $isImmediatePaid = $policyAware
                        && $workflow === RouteBranchCollectionSettingsService::WORKFLOW_IMMEDIATE_PAID;
                    $isPerOrderCollection = $policyAware
                        && $workflow === RouteBranchCollectionSettingsService::WORKFLOW_PER_ORDER_COLLECTION;
                    $policyBlocks = $this->policyPreflightBlocks($branchPolicy, $collectionResponsibility, $preSales);
                    if ($policyBlocks !== []) {
                        $firstBlock = $policyBlocks[0];
                        throw ValidationException::withMessages([$firstBlock['reason_code'] => $firstBlock['message']]);
                    }
                    $agreedMethodSnapshots = [];
                    $collectionsByPreSale = [];

                    $eligibilityByPreSale = [];
                    foreach ($preSales as $preSale) {
                        if (! in_array($preSale->agreed_payment_method, self::PAYMENT_METHODS, true)) {
                            throw ValidationException::withMessages(['agreed_payment_method' => 'Cada preventa preparada debe tener un método de pago acordado antes de entregar.']);
                        }

                        $agreedMethodSnapshots[$preSale->id] = $preSale->agreed_payment_method;
                        $collections = RoutePreSaleCollection::query()
                            ->where('business_id', $businessId)->where('branch_id', $branchId)->where('pre_sale_id', $preSale->id)
                            ->whereIn('status', ['captured', 'linked'])->orderBy('id')->lockForUpdate()->get();
                        if ($collections->count() > 1 || ($collections->isNotEmpty() && $collections->first()->status !== 'captured')) {
                            throw ValidationException::withMessages(['collection' => 'La preventa tiene un cobro previo inconsistente.']);
                        }
                        $collection = $collections->first();
                        $collectionsByPreSale[$preSale->id] = $collection;
                        if (! $policyAware && $collectionResponsibility === 'pre_seller' && ! $collection) {
                            throw ValidationException::withMessages(['collection' => 'Debe registrar el cobro antes de generar el comprobante.']);
                        }

                        $eligibility = $this->eligibility->persist($preSale);
                        if ((bool) ($settings?->route_pre_sale_require_fel_eligible_customer ?? false) && ! $eligibility['eligible']) {
                            throw ValidationException::withMessages(['pre_sale' => $eligibility['reason']]);
                        }
                        $eligibilityByPreSale[$preSale->id] = $eligibility;
                    }

                    $requiresOpenCashSession = ! $policyAware;
                    $cashSession = null;
                    if ($isImmediatePaid && $preSales->contains(fn (PreSale $preSale) => $collectionsByPreSale[$preSale->id] === null)) {
                        $cashSession = CashRegister::currentOpenSession($businessId, true, $branchId);
                        if (! $cashSession) {
                            throw ValidationException::withMessages([
                                'cash_session_required' => 'Debe abrir una caja para generar ventas con cobro inmediato.',
                            ]);
                        }
                    } elseif ($requiresOpenCashSession) {
                        $this->cash->requireOpen($businessId, $branchId, true);
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
                        'collection_workflow_mode_snapshot' => $branchPolicy['collection_workflow_mode'] ?? null,
                        'allowed_payment_methods_snapshot' => $branchPolicy['allowed_payment_methods'] ?? null,
                        'primary_payment_method_snapshot' => $branchPolicy['primary_payment_method'] ?? null,
                        'operation_settings_snapshotted_at' => now(),
                    ]);

                    $totalItems = 0;
                    $totalAmount = 0.0;
                    $automaticFelSales = [];

                    foreach ($preSales as $preSale) {
                        $collection = $collectionsByPreSale[$preSale->id];
                        if ($collection && bccomp((string) $collection->amount, (string) $preSale->total, 2) !== 0) {
                            throw ValidationException::withMessages(['collection' => 'El cobro debe coincidir con el total final de la preventa.']);
                        }
                        $receipt = $this->receipts->convertToInternalReceipt($preSale, [
                            'idempotency_key' => 'route-delivery-'.hash('sha256', "{$batch->id}:{$preSale->id}:{$idempotencyKey}"),
                            'payment_condition' => ($collection || $isImmediatePaid) ? 'paid' : ($isPerOrderCollection || $collectionResponsibility === 'delivery_agent' ? 'unpaid' : 'paid'),
                            'payment_method' => $collection?->payment_method ?? ($isImmediatePaid ? $agreedMethodSnapshots[$preSale->id] : null),
                            'skip_payment_posting' => $collection !== null,
                            'note' => "Entrega de ruta #{$batch->id}",
                        ], $user, $requiresOpenCashSession, $cashSession);
                        $sale = $preSale->refresh()->convertedSale()->withCount('items')->firstOrFail();
                        if ($collection) {
                            if ($policyAware && bccomp((string) $collection->amount, (string) $sale->total, 2) !== 0) {
                                throw ValidationException::withMessages(['collection' => 'El cobro previo no coincide con el total final de la venta preparada.']);
                            }
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

                        $entry = RouteDeliveryBatchPreSale::query()->create([
                            'route_delivery_batch_id' => $batch->id,
                            'pre_sale_id' => $preSale->id,
                            'sale_id' => $sale->id,
                            'status' => 'delivered',
                            'payment_method' => $collection?->payment_method ?? ($isImmediatePaid ? $agreedMethodSnapshots[$preSale->id] : $preSale->agreed_payment_method),
                            'agreed_payment_method_snapshot' => $policyAware ? $agreedMethodSnapshots[$preSale->id] : null,
                            'fel_dispatch_status' => $eligibleForAutomaticFel ? 'queued' : 'not_requested',
                            'error_message' => $automaticFelReason,
                        ]);
                        if ($isImmediatePaid && $collection === null) {
                            $payment = SalePayment::query()->where('sale_id', $sale->id)->lockForUpdate()->sole();
                            $payment->update(['route_immediate_paid_entry_id' => $entry->id]);
                        }

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

    /** @return array<int, array{pre_sale_id:?int,reason_code:string,message:string}> */
    public function policyPreflightPreview(RouteWorkDay $workDay): array
    {
        $businessId = (int) $workDay->business_id;
        $branchId = (int) $workDay->branch_id;
        $preSales = PreSale::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->where('route_work_day_id', $workDay->id)
            ->where('status', PreSale::STATUS_PICKED)
            ->whereNull('converted_sale_id')
            ->orderBy('id')
            ->get();

        if ($preSales->isEmpty()) {
            return [];
        }

        $setting = $this->branchCollectionSettings->forBranch($businessId, $branchId);

        try {
            $policy = $this->branchCollectionSettings->validatedStoredPolicy($setting);
        } catch (ValidationException) {
            return [[
                'pre_sale_id' => null,
                'reason_code' => 'route_collection_policy_invalid',
                'message' => 'La política de cobro de la sucursal es inválida.',
            ]];
        }

        $settings = TenantSetting::query()->where('business_id', $businessId)->first();
        $collectionResponsibility = $settings?->route_collection_responsibility === 'delivery_agent' ? 'delivery_agent' : 'pre_seller';

        if ($policy !== null
            && $policy['collection_workflow_mode'] === RouteBranchCollectionSettingsService::WORKFLOW_IMMEDIATE_PAID
            && $preSales->contains(fn (PreSale $preSale) => ! RoutePreSaleCollection::query()
                ->where('business_id', $businessId)->where('branch_id', $branchId)
                ->where('pre_sale_id', $preSale->id)->where('status', 'captured')->exists())
            && ! CashRegister::currentOpenSession($businessId, false, $branchId)) {
            return [[
                'pre_sale_id' => null,
                'reason_code' => 'cash_session_required',
                'message' => 'Debe abrir una caja para generar ventas con cobro inmediato.',
            ]];
        }

        return $this->policyPreflightBlocks($policy, $collectionResponsibility, $preSales);
    }

    /** @param iterable<PreSale> $preSales
     *  @return array<int, array{pre_sale_id:?int,reason_code:string,message:string}>
     */
    private function policyPreflightBlocks(?array $policy, string $collectionResponsibility, iterable $preSales): array
    {
        if ($policy === null) {
            return [];
        }

        $blocks = [];
        foreach ($preSales as $preSale) {
            $priorCollections = RoutePreSaleCollection::query()
                ->where('business_id', $preSale->business_id)->where('branch_id', $preSale->branch_id)
                ->where('pre_sale_id', $preSale->id)->whereIn('status', ['captured', 'linked'])->get();
            if ($priorCollections->count() > 1 || ($priorCollections->isNotEmpty() && $priorCollections->first()->status !== 'captured')) {
                $blocks[] = [
                    'pre_sale_id' => $preSale->id,
                    'reason_code' => 'pre_sale_collection_invalid',
                    'message' => 'La preventa tiene un cobro previo inconsistente.',
                ];
                continue;
            }
            $priorCollection = $priorCollections->first();
            if ($priorCollection && bccomp((string) $priorCollection->amount, (string) $preSale->total, 2) !== 0) {
                $blocks[] = [
                    'pre_sale_id' => $preSale->id,
                    'reason_code' => 'collection_amount_mismatch',
                    'message' => 'El cobro previo no coincide con el total final de la preventa.',
                ];
                continue;
            }
            if (! filled($preSale->agreed_payment_method)) {
                $blocks[] = [
                    'pre_sale_id' => $preSale->id,
                    'reason_code' => 'missing_agreed_payment_method',
                    'message' => 'Cada preventa preparada debe tener un método de pago acordado.',
                ];
                continue;
            }

            if (! $priorCollection && ! in_array($preSale->agreed_payment_method, $policy['allowed_payment_methods'], true)) {
                $blocks[] = [
                    'pre_sale_id' => $preSale->id,
                    'reason_code' => 'payment_method_not_allowed',
                    'message' => 'El método de pago acordado no está permitido para esta sucursal.',
                ];
            }
        }

        return $blocks;
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
