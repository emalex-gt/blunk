<?php

namespace App\Services\Routes;

use App\Models\OperationIdempotencyKey;
use App\Models\RouteCashSettlement;
use App\Models\RouteCashSettlementItem;
use App\Models\RouteCashSettlementVariance;
use App\Models\RouteDeliveryCollection;
use App\Models\RoutePreSaleCollection;
use App\Models\User;
use App\Support\CashRegister;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteCashSettlementService
{
    public function confirm(RouteCashSettlement $settlement, User $actor, int $receivedBy, mixed $receivedAmount, ?string $notes, string $key, array $variance = []): IdempotencyResult
    {
        $businessId = (int) $settlement->business_id;
        $branchId = (int) $settlement->branch_id;
        if (blank($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'La llave de idempotencia es obligatoria.']);
        }
        if (! is_numeric($receivedAmount)) {
            throw ValidationException::withMessages(['received_amount' => 'El efectivo recibido no es válido.']);
        }

        $amount = round((float) $receivedAmount, 2);

        return app(IdempotencyService::class)->run(
            $businessId, $branchId, $actor->id, 'route_cash_settlement_confirm', $key,
            ['settlement_id' => $settlement->id, 'received_by' => $receivedBy, 'received_amount' => $amount, 'notes' => $notes, 'variance' => $variance],
            function () use ($settlement, $actor, $receivedBy, $amount, $notes, $businessId, $branchId, $key, $variance) {
                return DB::transaction(function () use ($settlement, $actor, $receivedBy, $amount, $notes, $businessId, $branchId, $key, $variance) {
                    $this->assertActorScope($actor, $businessId, $branchId);
                    $locked = RouteCashSettlement::query()
                        ->where('business_id', $businessId)
                        ->where('branch_id', $branchId)
                        ->whereKey($settlement->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    if ($locked->status !== 'draft') {
                        throw ValidationException::withMessages(['settlement' => 'Sólo se puede confirmar una liquidación en borrador.']);
                    }

                    $receiver = User::query()->whereKey($receivedBy)->lockForUpdate()->firstOrFail();
                    if (! $receiver->is_active || (int) $receiver->business_id !== $businessId || (int) $receiver->current_branch_id !== $branchId) {
                        throw ValidationException::withMessages(['received_by' => 'Quien recibe el efectivo debe estar activo en la sucursal actual.']);
                    }

                    $items = RouteCashSettlementItem::query()
                        ->where('route_cash_settlement_id', $locked->id)
                        ->where('is_active', true)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    if ($items->isEmpty()) {
                        throw ValidationException::withMessages(['items' => 'La liquidación no tiene cobros activos para confirmar.']);
                    }

                    [$preCollections, $deliveryCollections] = $this->lockAndValidateCollections($items, $locked);
                    $expected = round((float) $items->sum(fn (RouteCashSettlementItem $item) => (float) $item->amount_snapshot), 2);
                    $difference = round($amount - $expected, 2);
                    if ($amount <= 0) {
                        throw ValidationException::withMessages(['received_amount' => 'Debe recibirse efectivo físico mayor que cero para confirmar.']);
                    }
                    if ($difference !== 0.0) {
                        $reason = $variance['reason_code'] ?? null;
                        $explanation = trim((string) ($variance['explanation'] ?? ''));
                        if (($variance['confirmed'] ?? false) !== true || ! $reason || $explanation === '') {
                            throw ValidationException::withMessages(['received_amount' => 'La diferencia exige motivo, explicación y confirmación explícita.']);
                        }
                        $allowed = $difference < 0 ? ['counting_difference','collector_reported_loss','missing_cash','other'] : ['counting_difference','unidentified_extra_cash','other'];
                        if (! in_array($reason, $allowed, true)) {
                            throw ValidationException::withMessages(['variance_reason_code' => 'El motivo no corresponde al tipo de diferencia.']);
                        }
                    }

                    $session = CashRegister::requireOpenSession($businessId, 'Debe existir una caja abierta actual para confirmar la recepción física.', true, $branchId);
                    $movement = CashRegister::recordMovement(
                        $session,
                        'route_cash_settlement',
                        $amount,
                        'route_cash_settlement',
                        $locked->id,
                        "Liquidación de efectivo del cobrador #{$locked->collector_user_id}",
                        $actor->id,
                    );
                    if ($difference !== 0.0) {
                        RouteCashSettlementVariance::query()->create([
                            'business_id' => $businessId, 'branch_id' => $branchId, 'route_cash_settlement_id' => $locked->id,
                            'collector_user_id' => $locked->collector_user_id, 'difference_amount' => $difference, 'status' => 'open',
                            'reason_code' => $variance['reason_code'], 'explanation' => trim($variance['explanation']),
                            'opened_by' => $actor->id, 'opened_at' => now(),
                        ]);
                    }

                    RoutePreSaleCollection::query()->whereIn('id', $preCollections->keys())->update(['custody_status' => 'posted_to_branch_cash']);
                    RouteDeliveryCollection::query()->whereIn('id', $deliveryCollections->keys())->update([
                        'custody_status' => 'posted_to_branch_cash',
                        'cash_posting_state' => 'posted_to_current_session',
                    ]);
                    $idempotencyId = OperationIdempotencyKey::query()
                        ->where('business_id', $businessId)
                        ->where('branch_id', $branchId)
                        ->where('user_id', $actor->id)
                        ->where('operation_type', 'route_cash_settlement_confirm')
                        ->where('idempotency_key', $key)
                        ->value('id');
                    $locked->update([
                        'received_by' => $receiver->id,
                        'recorded_by' => $actor->id,
                        'confirmed_by' => $actor->id,
                        'cash_register_session_id' => $session->id,
                        'cash_movement_id' => $movement->id,
                        'operation_idempotency_key_id' => $idempotencyId,
                        'expected_amount' => $expected,
                        'received_amount' => $amount,
                        'difference_amount' => $difference,
                        'notes' => filled($notes) ? trim($notes) : null,
                        'status' => 'confirmed',
                        'confirmed_at' => now(),
                    ]);

                    return ['result_id' => $locked->id, 'response_payload' => ['settlement_id' => $locked->id]];
                });
            },
            'route_cash_settlement',
        );
    }

    /** @return array{0: \Illuminate\Support\Collection<int, RoutePreSaleCollection>, 1: \Illuminate\Support\Collection<int, RouteDeliveryCollection>} */
    private function lockAndValidateCollections($items, RouteCashSettlement $settlement): array
    {
        $preIds = [];
        $deliveryIds = [];
        foreach ($items as $item) {
            $hasPre = $item->route_pre_sale_collection_id !== null;
            $hasDelivery = $item->route_delivery_collection_id !== null;
            if ($hasPre === $hasDelivery) {
                throw ValidationException::withMessages(['items' => 'Un item de liquidación tiene un origen inválido.']);
            }
            if ($hasPre) {
                $preIds[] = (int) $item->route_pre_sale_collection_id;
            } else {
                $deliveryIds[] = (int) $item->route_delivery_collection_id;
            }
        }

        $pre = RoutePreSaleCollection::query()->whereIn('id', $preIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $delivery = RouteDeliveryCollection::query()->captured()->whereIn('id', $deliveryIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($pre->count() !== count($preIds) || $delivery->count() !== count($deliveryIds)) {
            throw ValidationException::withMessages(['items' => 'Uno de los cobros de la liquidación ya no existe.']);
        }

        foreach ($items as $item) {
            $collection = $item->route_pre_sale_collection_id !== null
                ? $pre->get((int) $item->route_pre_sale_collection_id)
                : $delivery->get((int) $item->route_delivery_collection_id);
            if ((int) $collection->business_id !== (int) $settlement->business_id
                || (int) $collection->branch_id !== (int) $settlement->branch_id
                || (int) $collection->collected_by !== (int) $settlement->collector_user_id
                || $collection->payment_method !== 'cash'
                || $collection->custody_status !== 'held_by_collector'
                || round((float) $collection->amount, 2) !== round((float) $item->amount_snapshot, 2)) {
                throw ValidationException::withMessages(['items' => 'Un cobro ya no es elegible para liquidación.']);
            }
        }

        return [$pre, $delivery];
    }

    private function assertActorScope(User $actor, int $businessId, int $branchId): void
    {
        if (! $actor->is_active || (int) $actor->business_id !== $businessId || (int) $actor->current_branch_id !== $branchId) {
            abort(403);
        }
    }
}
