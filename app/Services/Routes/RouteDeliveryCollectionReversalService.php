<?php

namespace App\Services\Routes;

use App\Models\OperationIdempotencyKey;
use App\Models\RouteCashSettlementItem;
use App\Models\RouteCashSettlement;
use App\Models\RouteDeliveryCollection;
use App\Models\RouteDeliveryCollectionReversal;
use App\Models\RouteDeliveryStop;
use App\Models\RouteExternalDeliveryReconciliationItem;
use App\Models\RoutePendingCollectionCase;
use App\Models\RoutePendingCollectionEvent;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteDeliveryCollectionReversalService
{
    private const REASONS = ['payment_recorded_by_mistake', 'wrong_customer', 'duplicate_collection', 'wrong_amount', 'other'];

    public function reverse(RouteDeliveryCollection $collection, array $data, User $actor, string $key): IdempotencyResult
    {
        abort_unless(Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_COLLECTIONS_REVERSE), 403);
        if (blank($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'La llave de idempotencia es obligatoria.']);
        }
        $reason = (string) ($data['reason_code'] ?? '');
        if (! in_array($reason, self::REASONS, true)) {
            throw ValidationException::withMessages(['reason_code' => 'El motivo de reversa no es válido.']);
        }
        $explanation = trim((string) ($data['explanation'] ?? ''));
        if ($explanation === '') {
            throw ValidationException::withMessages(['explanation' => 'La explicación de la reversa es obligatoria.']);
        }
        if (($data['confirmed'] ?? false) !== true) {
            throw ValidationException::withMessages(['confirmed' => 'Debe confirmar explícitamente la reversa del cobro.']);
        }

        return app(IdempotencyService::class)->run(
            (int) $collection->business_id,
            (int) $collection->branch_id,
            (int) $actor->id,
            'route_delivery_collection_reverse',
            $key,
            ['collection_id' => $collection->id, 'reason_code' => $reason, 'explanation' => $explanation, 'confirmed' => true, 'confirm_cash_adjustment' => (bool) ($data['confirm_cash_adjustment'] ?? false)],
            fn () => $this->reverseLocked($collection, $data, $actor, $key, $reason, $explanation),
            'route_delivery_collection_reversal',
        );
    }

    private function reverseLocked(RouteDeliveryCollection $input, array $data, User $actor, string $key, string $reason, string $explanation): array
    {
        return DB::transaction(function () use ($input, $data, $actor, $key, $reason, $explanation) {
            $preview = RouteDeliveryCollection::query()->whereKey($input->id)->firstOrFail();
            $businessId = (int) $preview->business_id;
            $branchId = (int) $preview->branch_id;
            abort_unless($actor->is_active && (int) $actor->business_id === $businessId && (int) $actor->current_branch_id === $branchId, 403);

            $source = $this->lockDeliveredSource($preview, $businessId, $branchId);
            $sale = Sale::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($preview->sale_id)->lockForUpdate()->firstOrFail();
            $payments = SalePayment::query()->where('sale_id', $sale->id)->orderBy('id')->lockForUpdate()->get();
            $case = RoutePendingCollectionCase::query()->where('sale_id', $sale->id)->lockForUpdate()->first();
            $settlementIds = RouteCashSettlementItem::query()
                ->where('route_delivery_collection_id', $preview->id)
                ->orderBy('route_cash_settlement_id')
                ->pluck('route_cash_settlement_id')
                ->unique()
                ->values();
            $settlements = RouteCashSettlement::query()
                ->whereIn('id', $settlementIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $settlementItems = RouteCashSettlementItem::query()
                ->where('route_delivery_collection_id', $preview->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $locked = RouteDeliveryCollection::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($preview->id)->lockForUpdate()->firstOrFail();

            // A settlement draft locks the collection before reserving it. Once this lock is held,
            // re-reading the active items closes the read/insert window without changing the lock order.
            $settlementItems = RouteCashSettlementItem::query()
                ->where('route_delivery_collection_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertEligible($locked, $sale, $payments, $source, $case, $settlementItems, $settlements);
            $payment = $payments->firstWhere('route_delivery_collection_id', $locked->id);
            $now = now()->startOfSecond();
            $cashType = $this->cashCorrectionType($locked, $data, $businessId, $branchId);
            $operationId = OperationIdempotencyKey::query()
                ->where('business_id', $businessId)->where('branch_id', $branchId)->where('user_id', $actor->id)
                ->where('operation_type', 'route_delivery_collection_reverse')->where('idempotency_key', $key)->value('id');

            $reversal = RouteDeliveryCollectionReversal::query()->create([
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'route_delivery_collection_id' => $locked->id,
                'sale_payment_id' => $payment->id,
                'route_pending_collection_case_id' => $case?->id,
                'previous_case_resolved_by' => $case?->resolved_by,
                'previous_case_resolved_at' => $case?->resolved_at,
                'reason_code' => $reason,
                'explanation' => $explanation,
                'reversed_by' => $actor->id,
                'reversed_at' => $now,
                // The current-session branch updates this transitional valid state after its movement has a reversal id.
                'cash_correction_type' => $cashType === 'current_open_session_adjustment' ? 'none' : $cashType,
                'operation_idempotency_key_id' => $operationId,
            ]);

            if ($cashType === 'current_open_session_adjustment') {
                $session = \App\Support\CashRegister::requireOpenSession($businessId, 'La sesión original debe seguir siendo la caja abierta actual.', true, $branchId);
                $movement = \App\Support\CashRegister::recordMovement(
                    $session,
                    'route_delivery_collection_reversal_current_session',
                    -round((float) $locked->amount, 2),
                    'route_delivery_collection_reversal',
                    $reversal->id,
                    "Reversa administrativa de cobro de entrega #{$locked->id}",
                    $actor->id,
                );
                $reversal->update([
                    'cash_correction_type' => $cashType,
                    'compensating_cash_movement_id' => $movement->id,
                ]);
            }

            $locked->update(['status' => 'reversed']);
            $payment->update(['status' => 'reversed']);
            $sale->update(['payment_status' => 'unpaid', 'amount_paid' => 0, 'payment_method' => null]);

            if ($case) {
                $case->update([
                    'status' => 'open',
                    'resolved_at' => null,
                    'resolved_by' => null,
                    'resolution_route_delivery_collection_id' => null,
                ]);
                RoutePendingCollectionEvent::query()->create([
                    'route_pending_collection_case_id' => $case->id,
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'type' => 'collection_reversed',
                    'note' => "Cobro revertido: {$reason}.",
                    'occurred_at' => $now,
                    'recorded_by' => $actor->id,
                ]);
            }

            return ['result_id' => $reversal->id, 'response_payload' => ['reversal_id' => $reversal->id, 'collection_id' => $locked->id]];
        });
    }

    private function lockDeliveredSource(RouteDeliveryCollection $collection, int $businessId, int $branchId): RouteExternalDeliveryReconciliationItem|RouteDeliveryStop
    {
        if ($collection->delivery_origin === 'external_reconciliation') {
            $source = RouteExternalDeliveryReconciliationItem::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($collection->route_external_delivery_reconciliation_item_id)->lockForUpdate()->firstOrFail();
            if ($source->delivery_tracking_snapshot !== 'external' || $source->collection_responsibility_snapshot !== 'delivery_agent' || $source->delivery_status !== 'delivered') {
                throw ValidationException::withMessages(['delivery' => 'La entrega externa ya no es elegible para reversa.']);
            }
            return $source;
        }
        if ($collection->delivery_origin === 'in_app_stop') {
            $source = RouteDeliveryStop::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($collection->route_delivery_stop_id)->lockForUpdate()->firstOrFail();
            if ($source->delivery_tracking_snapshot !== 'in_app' || $source->collection_responsibility_snapshot !== 'delivery_agent' || $source->status !== 'delivered') {
                throw ValidationException::withMessages(['delivery' => 'La entrega en aplicación ya no es elegible para reversa.']);
            }
            return $source;
        }
        throw ValidationException::withMessages(['collection' => 'El origen del cobro no es reversible.']);
    }

    private function assertEligible(RouteDeliveryCollection $collection, Sale $sale, $payments, RouteExternalDeliveryReconciliationItem|RouteDeliveryStop $source, ?RoutePendingCollectionCase $case, $settlementItems, $settlements): void
    {
        if ($collection->status !== 'captured') throw ValidationException::withMessages(['collection' => 'El cobro ya fue reversado.']);
        if ((int) $collection->sale_id !== (int) $sale->id || (int) $collection->business_id !== (int) $sale->business_id || (int) $collection->branch_id !== (int) $sale->branch_id) throw ValidationException::withMessages(['collection' => 'El cobro no coincide con la venta.']);
        if ($sale->payment_status !== 'paid' || round((float) $sale->amount_paid, 2) !== round((float) $collection->amount, 2) || round((float) $sale->total, 2) !== round((float) $collection->amount, 2) || $sale->is_credit_sale || (float) $sale->credit_balance !== 0.0) throw ValidationException::withMessages(['sale' => 'La venta no es elegible para reversa total.']);
        $captured = $payments->where('status', 'captured');
        $payment = $captured->firstWhere('route_delivery_collection_id', $collection->id);
        if ($captured->count() !== 1 || ! $payment || round((float) $payment->amount, 2) !== round((float) $collection->amount, 2) || $payment->method !== $collection->payment_method) throw ValidationException::withMessages(['payment' => 'El pago asociado no coincide con el cobro capturado.']);
        if (RouteDeliveryCollection::query()->captured()->where('sale_id', $sale->id)->whereKeyNot($collection->id)->exists()) throw ValidationException::withMessages(['collection' => 'La venta ya tiene otro cobro capturado.']);
        if ($case && ($case->status !== 'resolved' || (int) $case->resolution_route_delivery_collection_id !== (int) $collection->id || ! $case->resolved_by || ! $case->resolved_at)) throw ValidationException::withMessages(['case' => 'El case persistido no fue resuelto correctamente por este cobro.']);
        if ($settlementItems->contains(fn (RouteCashSettlementItem $item) => $item->is_active && in_array($settlements->get($item->route_cash_settlement_id)?->status, ['draft', 'confirmed'], true))) throw ValidationException::withMessages(['settlement' => 'El cobro está reservado o confirmado en una liquidación.']);
    }

    private function cashCorrectionType(RouteDeliveryCollection $collection, array $data, int $businessId, int $branchId): string
    {
        if ($collection->payment_method !== 'cash' || $collection->custody_status !== 'posted_to_branch_cash') return 'none';
        $movement = \App\Models\CashMovement::query()->whereKey($collection->cash_movement_id)->lockForUpdate()->first();
        if (! $movement) throw ValidationException::withMessages(['cash' => 'El efectivo posteado no conserva su movimiento original.']);
        $session = $movement->session()->lockForUpdate()->firstOrFail();
        if ($session->status === 'closed') return 'historical_closed_session_ledger';
        if ($session->status !== 'open' || (int) $session->business_id !== $businessId || (int) $session->branch_id !== $branchId || (int) \App\Support\CashRegister::currentOpenSession($businessId, true, $branchId)?->id !== (int) $session->id) throw ValidationException::withMessages(['cash' => 'Sólo puede ajustarse la misma sesión original aún abierta.']);
        if (($data['confirm_cash_adjustment'] ?? false) !== true) throw ValidationException::withMessages(['confirm_cash_adjustment' => 'Debe confirmar el ajuste administrativo de caja actual.']);
        return 'current_open_session_adjustment';
    }
}
