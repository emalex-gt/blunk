<?php

namespace App\Services\Routes;

use App\Models\OperationIdempotencyKey;
use App\Models\PreSale;
use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RoutePostConversionCollection;
use App\Models\Sale;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\CashRegister;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoutePostConversionCollectionService
{
    public function __construct(private readonly RouteBranchCollectionSettingsService $branchCollectionSettings)
    {
    }

    public function collect(RouteDeliveryBatchPreSale $entry, array $data, User $actor, string $idempotencyKey): IdempotencyResult
    {
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'La llave de idempotencia es obligatoria.']);
        }

        $batch = RouteDeliveryBatch::query()->findOrFail($entry->route_delivery_batch_id);
        $businessId = (int) $batch->business_id;
        $branchId = (int) $batch->branch_id;

        return app(IdempotencyService::class)->run(
            $businessId, $branchId, $actor->id, 'route_post_conversion_collection_capture', $idempotencyKey,
            [
                'entry_id' => $entry->id,
                'payment_method' => $data['payment_method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'collected_by' => $data['collected_by'] ?? $actor->id,
                'override_reason' => $data['override_reason'] ?? null,
            ],
            function () use ($entry, $data, $actor, $businessId, $branchId, $idempotencyKey): array {
                return DB::transaction(function () use ($entry, $data, $actor, $businessId, $branchId, $idempotencyKey): array {
                    abort_unless((int) $actor->business_id === $businessId && (int) $actor->current_branch_id === $branchId && $actor->is_active, 403);
                    abort_unless((int) BranchInventory::activeBranch($businessId)->id === $branchId, 403);

                    $policy = $this->branchCollectionSettings->lockValidatedPolicyForExecution($businessId, $branchId);
                    if (! $policy) {
                        throw ValidationException::withMessages(['route_collection_policy_unavailable' => 'La política de cobro vigente de la sucursal no está disponible.']);
                    }

                    $lockedEntry = RouteDeliveryBatchPreSale::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                    $batch = RouteDeliveryBatch::query()->whereKey($lockedEntry->route_delivery_batch_id)->where('business_id', $businessId)->where('branch_id', $branchId)->lockForUpdate()->firstOrFail();
                    if ($batch->collection_workflow_mode_snapshot !== RouteBranchCollectionSettingsService::WORKFLOW_PER_ORDER_COLLECTION || $batch->collection_responsibility_snapshot !== 'pre_seller') {
                        throw ValidationException::withMessages(['collection' => 'La venta no pertenece a un cobro posterior del preventista.']);
                    }

                    $preSale = PreSale::query()->whereKey($lockedEntry->pre_sale_id)->where('business_id', $businessId)->where('branch_id', $branchId)->lockForUpdate()->firstOrFail();
                    $sale = Sale::query()->whereKey($lockedEntry->sale_id)->where('business_id', $businessId)->where('branch_id', $branchId)->lockForUpdate()->firstOrFail();
                    if ((int) $preSale->converted_sale_id !== (int) $sale->id) {
                        throw ValidationException::withMessages(['collection' => 'La trazabilidad entre preventa y venta es inconsistente.']);
                    }
                    if ($sale->payment_status !== 'unpaid' || (string) $sale->amount_paid !== '0.00' || (string) $sale->credit_balance !== '0.00' || (bool) $sale->is_credit_sale || $sale->status === 'cancelled') {
                        throw ValidationException::withMessages(['sale' => 'La venta no está disponible para cobrar.']);
                    }
                    if (RoutePostConversionCollection::query()->captured()->where('sale_id', $sale->id)->lockForUpdate()->exists() || $sale->capturedPayments()->lockForUpdate()->exists()) {
                        throw ValidationException::withMessages(['collection' => 'La venta ya tiene un cobro activo.']);
                    }

                    $method = (string) ($data['payment_method'] ?? '');
                    if (! in_array($method, $policy['allowed_payment_methods'], true)) {
                        throw ValidationException::withMessages(['payment_method' => 'La forma de pago no está permitida por la política vigente de la sucursal.']);
                    }
                    $collectorId = (int) ($data['collected_by'] ?? $actor->id);
                    $collector = User::query()->whereKey($collectorId)->where('business_id', $businessId)->where('current_branch_id', $branchId)->where('is_active', true)->first();
                    if (! $collector) {
                        throw ValidationException::withMessages(['collected_by' => 'El cobrador debe ser un usuario activo de la sucursal.']);
                    }
                    $amount = (string) $sale->total;
                    $custodyPolicy = TenantSetting::query()->where('business_id', $businessId)->value('route_cash_custody_policy') ?? 'collector_custody_until_settlement';
                    $postCash = $method === 'cash' && $custodyPolicy === 'immediate_branch_register';
                    $session = $postCash ? CashRegister::requireOpenSession($businessId, 'Debe existir una caja abierta para recibir efectivo ahora.', true, $branchId) : null;
                    $operationId = OperationIdempotencyKey::query()->where('business_id', $businessId)->where('branch_id', $branchId)->where('user_id', $actor->id)->where('operation_type', 'route_post_conversion_collection_capture')->where('idempotency_key', $idempotencyKey)->value('id');

                    $collection = RoutePostConversionCollection::query()->create([
                        'business_id' => $businessId, 'branch_id' => $branchId, 'route_delivery_batch_pre_sale_id' => $lockedEntry->id,
                        'pre_sale_id' => $preSale->id, 'sale_id' => $sale->id, 'collected_by' => $collector->id, 'recorded_by' => $actor->id,
                        'amount' => $amount, 'payment_method' => $method, 'reference' => $data['reference'] ?? null, 'details' => $data['details'] ?? null,
                        'collected_at' => $data['collected_at'] ?? now(), 'cash_custody_policy_snapshot' => $custodyPolicy,
                        // The strict persistence check requires a posted collection to already reference
                        // its movement. Keep the insert valid, then atomically promote custody after posting.
                        'custody_status' => $method !== 'cash' ? 'not_applicable' : 'held_by_collector',
                        'cash_posting_state' => $method !== 'cash' ? 'not_applicable' : 'awaiting_physical_receipt',
                        'cash_register_session_id' => null, 'operation_idempotency_key_id' => $operationId,
                        'override_reason' => $data['override_reason'] ?? null, 'status' => 'captured',
                    ]);
                    if ($postCash) {
                        $movement = CashRegister::recordMovement($session, 'sale_cash', (float) $amount, 'route_post_conversion_collection', $collection->id, "Cobro posterior de ruta #{$lockedEntry->id}", $actor->id);
                        DB::table('route_post_conversion_collections')->where('id', $collection->id)->update([
                            'custody_status' => 'posted_to_branch_cash', 'cash_posting_state' => 'posted_to_current_session',
                            'cash_register_session_id' => $session->id, 'cash_movement_id' => $movement->id, 'updated_at' => now(),
                        ]);
                        $collection->refresh();
                    }
                    $sale->payments()->create([
                        'business_id' => $businessId, 'method' => $method, 'amount' => $amount, 'reference' => $data['reference'] ?? null,
                        'details' => $data['details'] ?? null, 'collected_by' => $collector->id, 'collected_at' => $collection->collected_at,
                        'cash_register_session_id' => $session?->id, 'route_post_conversion_collection_id' => $collection->id,
                    ]);
                    $sale->update(['payment_status' => 'paid', 'amount_paid' => $amount, 'credit_balance' => '0.00', 'is_credit_sale' => false, 'due_date' => null, 'payment_method' => $method]);
                    $lockedEntry->update(['payment_method' => $method]);

                    return ['result_id' => $collection->id, 'response_payload' => ['collection_id' => $collection->id]];
                });
            },
            'route_post_conversion_collection',
        );
    }
}
