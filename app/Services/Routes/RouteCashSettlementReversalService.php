<?php

namespace App\Services\Routes;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\OperationIdempotencyKey;
use App\Models\RouteCashSettlement;
use App\Models\RouteCashSettlementItem;
use App\Models\RouteCashSettlementReversal;
use App\Models\RouteCashSettlementVariance;
use App\Models\RouteCashSettlementVarianceResolution;
use App\Models\RouteDeliveryCollection;
use App\Models\RoutePostConversionCollection;
use App\Models\RoutePreSaleCollection;
use App\Models\User;
use App\Support\CashRegister;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteCashSettlementReversalService
{
    public function reverse(RouteCashSettlement $settlement, array $data, User $actor, string $key): IdempotencyResult
    {
        $businessId = (int) $settlement->business_id;
        $branchId = (int) $settlement->branch_id;
        $reason = (string) ($data['reason_code'] ?? '');
        $explanation = trim((string) ($data['explanation'] ?? ''));
        $confirmCashAdjustment = ($data['confirm_cash_adjustment'] ?? false) === true;

        if (blank($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'La llave de idempotencia es obligatoria.']);
        }
        if ($reason !== 'cash_not_received_by_branch') {
            throw ValidationException::withMessages(['reason_code' => 'El motivo de reversa no es válido.']);
        }
        if ($explanation === '') {
            throw ValidationException::withMessages(['explanation' => 'La explicación de la reversa es obligatoria.']);
        }

        return app(IdempotencyService::class)->run(
            $businessId,
            $branchId,
            $actor->id,
            'route_cash_settlement_reverse',
            $key,
            [
                'settlement_id' => $settlement->id,
                'reason_code' => $reason,
                'explanation' => $explanation,
                'confirm_cash_adjustment' => $confirmCashAdjustment,
            ],
            function () use ($settlement, $actor, $businessId, $branchId, $key, $reason, $explanation, $confirmCashAdjustment) {
                return DB::transaction(function () use ($settlement, $actor, $businessId, $branchId, $key, $reason, $explanation, $confirmCashAdjustment) {
                    $this->assertActorScope($actor, $businessId, $branchId);

                    $locked = RouteCashSettlement::query()
                        ->where('business_id', $businessId)
                        ->where('branch_id', $branchId)
                        ->whereKey($settlement->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $this->assertReversibleSettlement($locked);

                    $items = RouteCashSettlementItem::query()
                        ->where('route_cash_settlement_id', $locked->id)
                        ->where('is_active', true)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    if ($items->isEmpty()) {
                        throw ValidationException::withMessages(['settlement' => 'La liquidación no tiene cobros activos para revertir.']);
                    }

                    [$preCollections, $deliveryCollections, $postCollections] = $this->lockAndValidateCollections($items, $locked);
                    $this->assertNoVarianceOrResolution($locked);

                    [$originalMovement, $originalSession] = $this->lockAndValidateOriginalMovement($locked);
                    if (RouteCashSettlementReversal::query()->where('route_cash_settlement_id', $locked->id)->lockForUpdate()->exists()) {
                        throw ValidationException::withMessages(['settlement' => 'La liquidación ya fue revertida.']);
                    }
                    $cashCorrectionType = 'ledger_only';
                    $compensatingMovement = null;

                    if ($originalSession->status === 'open') {
                        $currentSession = CashRegister::currentOpenSession($businessId, true, $branchId);
                        if (! $currentSession || (int) $currentSession->id !== (int) $originalSession->id) {
                            throw ValidationException::withMessages(['settlement' => 'La sesión original está abierta pero ya no es la caja actual de la sucursal; la reversa debe registrarse sólo en libro.']);
                        }
                        if (! $confirmCashAdjustment) {
                            throw ValidationException::withMessages(['confirm_cash_adjustment' => 'Confirme explícitamente el ajuste físico de efectivo en la caja original.']);
                        }
                        $cashCorrectionType = 'current_session_adjustment';
                        $compensatingMovement = CashRegister::recordMovement(
                            $originalSession,
                            'route_cash_settlement_reversal_current_session',
                            -round((float) $originalMovement->amount, 2),
                            'route_cash_settlement_reversal',
                            null,
                            "Reversa de liquidación de efectivo #{$locked->id}",
                            $actor->id,
                        );
                    } elseif ($originalSession->status !== 'closed') {
                        throw ValidationException::withMessages(['settlement' => 'La sesión original de la liquidación no tiene un estado válido para reversa.']);
                    }

                    $idempotencyId = OperationIdempotencyKey::query()
                        ->where('business_id', $businessId)
                        ->where('branch_id', $branchId)
                        ->where('user_id', $actor->id)
                        ->where('operation_type', 'route_cash_settlement_reverse')
                        ->where('idempotency_key', $key)
                        ->value('id');
                    $reversal = RouteCashSettlementReversal::query()->create([
                        'business_id' => $businessId,
                        'branch_id' => $branchId,
                        'route_cash_settlement_id' => $locked->id,
                        'reason_code' => $reason,
                        'explanation' => $explanation,
                        'reversed_by' => $actor->id,
                        'reversed_at' => now(),
                        'cash_correction_type' => $cashCorrectionType,
                        'compensating_cash_movement_id' => $compensatingMovement?->id,
                        'operation_idempotency_key_id' => $idempotencyId,
                    ]);
                    if ($compensatingMovement) {
                        $compensatingMovement->update(['reference_id' => $reversal->id]);
                    }

                    RouteCashSettlementItem::query()
                        ->whereIn('id', $items->pluck('id'))
                        ->update([
                            'is_active' => false,
                            'reversed_by_route_cash_settlement_reversal_id' => $reversal->id,
                        ]);
                    RoutePreSaleCollection::query()->whereIn('id', $preCollections->keys())->update(['custody_status' => 'held_by_collector']);
                    RouteDeliveryCollection::query()->whereIn('id', $deliveryCollections->keys())->update([
                        'custody_status' => 'held_by_collector',
                        'cash_posting_state' => 'awaiting_physical_receipt',
                    ]);
                    RoutePostConversionCollection::query()->whereIn('id', $postCollections->keys())->update([
                        'custody_status' => 'held_by_collector',
                        'cash_posting_state' => 'awaiting_physical_receipt',
                    ]);
                    $locked->update(['status' => 'reversed']);

                    return ['result_id' => $reversal->id, 'response_payload' => ['settlement_id' => $locked->id, 'reversal_id' => $reversal->id]];
                });
            },
            'route_cash_settlement_reversal',
        );
    }

    private function assertReversibleSettlement(RouteCashSettlement $settlement): void
    {
        if ($settlement->status !== 'confirmed') {
            throw ValidationException::withMessages(['settlement' => 'Sólo se puede revertir una liquidación confirmada.']);
        }
        if ($settlement->cash_movement_id === null || $settlement->cash_register_session_id === null || $settlement->received_amount === null) {
            throw ValidationException::withMessages(['settlement' => 'La liquidación confirmada no conserva su movimiento de efectivo original.']);
        }
        if (round((float) $settlement->difference_amount, 2) !== 0.0
            || round((float) $settlement->expected_amount, 2) !== round((float) $settlement->received_amount, 2)) {
            throw ValidationException::withMessages(['settlement' => 'Sólo se puede revertir una liquidación exacta y sin diferencia.']);
        }
    }

    private function assertNoVarianceOrResolution(RouteCashSettlement $settlement): void
    {
        $variances = RouteCashSettlementVariance::query()
            ->where('route_cash_settlement_id', $settlement->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        RouteCashSettlementVarianceResolution::query()
            ->whereIn('route_cash_settlement_variance_id', $variances->pluck('id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($variances->isNotEmpty()) {
            throw ValidationException::withMessages(['settlement' => 'No se puede revertir una liquidación con diferencias o resoluciones de diferencia.']);
        }
    }

    /** @return array{0: CashMovement, 1: CashRegisterSession} */
    private function lockAndValidateOriginalMovement(RouteCashSettlement $settlement): array
    {
        $movement = CashMovement::query()->whereKey($settlement->cash_movement_id)->lockForUpdate()->firstOrFail();
        $session = CashRegisterSession::query()->whereKey($settlement->cash_register_session_id)->lockForUpdate()->firstOrFail();
        if ((int) $movement->business_id !== (int) $settlement->business_id
            || (int) $movement->branch_id !== (int) $settlement->branch_id
            || (int) $movement->cash_register_session_id !== (int) $session->id
            || $movement->type !== 'route_cash_settlement'
            || $movement->reference_type !== 'route_cash_settlement'
            || (int) $movement->reference_id !== (int) $settlement->id
            || round((float) $movement->amount, 2) !== round((float) $settlement->received_amount, 2)
            || (int) $session->business_id !== (int) $settlement->business_id
            || (int) $session->branch_id !== (int) $settlement->branch_id) {
            throw ValidationException::withMessages(['settlement' => 'El movimiento de efectivo original no corresponde a esta liquidación.']);
        }

        return [$movement, $session];
    }

    /** @return array{0: Collection<int, RoutePreSaleCollection>, 1: Collection<int, RouteDeliveryCollection>, 2: Collection<int, RoutePostConversionCollection>} */
    private function lockAndValidateCollections(Collection $items, RouteCashSettlement $settlement): array
    {
        $preIds = $items->pluck('route_pre_sale_collection_id')->filter()->map(fn ($id) => (int) $id)->sort()->values()->all();
        $deliveryIds = $items->pluck('route_delivery_collection_id')->filter()->map(fn ($id) => (int) $id)->sort()->values()->all();
        $postIds = $items->pluck('route_post_conversion_collection_id')->filter()->map(fn ($id) => (int) $id)->sort()->values()->all();
        $pre = RoutePreSaleCollection::query()->whereIn('id', $preIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $delivery = RouteDeliveryCollection::query()->whereIn('id', $deliveryIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $post = RoutePostConversionCollection::query()->whereIn('id', $postIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($pre->count() !== count($preIds) || $delivery->count() !== count($deliveryIds) || $post->count() !== count($postIds)) {
            throw ValidationException::withMessages(['items' => 'Uno de los cobros de la liquidación ya no existe.']);
        }

        foreach ($items as $item) {
            $sources = array_filter([
                'pre' => $item->route_pre_sale_collection_id,
                'delivery' => $item->route_delivery_collection_id,
                'post' => $item->route_post_conversion_collection_id,
            ], fn ($id) => $id !== null);
            if (count($sources) !== 1) {
                throw ValidationException::withMessages(['items' => 'Un item de liquidación tiene un origen inválido.']);
            }
            $type = array_key_first($sources);
            $collection = match ($type) {
                'pre' => $pre->get((int) $item->route_pre_sale_collection_id),
                'delivery' => $delivery->get((int) $item->route_delivery_collection_id),
                'post' => $post->get((int) $item->route_post_conversion_collection_id),
            };
            $validStatus = $type === 'pre'
                ? in_array($collection->status, ['captured', 'linked'], true)
                : $collection->status === 'captured';
            if (! $validStatus
                || (int) $collection->business_id !== (int) $settlement->business_id
                || (int) $collection->branch_id !== (int) $settlement->branch_id
                || (int) $collection->collected_by !== (int) $settlement->collector_user_id
                || $collection->payment_method !== 'cash'
                || $collection->custody_status !== 'posted_to_branch_cash'
                || round((float) $collection->amount, 2) !== round((float) $item->amount_snapshot, 2)) {
                throw ValidationException::withMessages(['items' => 'Un cobro ya no corresponde a esta liquidación confirmada.']);
            }
        }

        return [$pre, $delivery, $post];
    }

    private function assertActorScope(User $actor, int $businessId, int $branchId): void
    {
        if (! $actor->is_active || (int) $actor->business_id !== $businessId || (int) $actor->current_branch_id !== $branchId) {
            abort(403);
        }
    }
}
