<?php

namespace App\Services\Routes;

use App\Models\CashMovement;
use App\Models\OperationIdempotencyKey;
use App\Models\RouteCashSettlement;
use App\Models\RouteCashSettlementItem;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RoutePostConversionCollection;
use App\Models\RoutePostConversionCollectionReversal;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\User;
use App\Support\CashRegister;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoutePostConversionCollectionReversalService
{
    private const REASONS = ['payment_recorded_by_mistake', 'wrong_customer', 'duplicate_collection', 'wrong_amount', 'other'];

    public function reverse(RoutePostConversionCollection $collection, array $data, User $actor, string $idempotencyKey): IdempotencyResult
    {
        $reason = (string) ($data['reason_code'] ?? '');
        if ($idempotencyKey === '' || ! in_array($reason, self::REASONS, true) || blank($data['explanation'] ?? null) || ($data['confirmed'] ?? false) !== true) {
            throw ValidationException::withMessages(['reversal' => 'La reversa requiere llave, motivo, explicación y confirmación explícita.']);
        }
        return app(IdempotencyService::class)->run(
            (int) $collection->business_id, (int) $collection->branch_id, (int) $actor->id,
            'route_post_conversion_collection_reverse', $idempotencyKey,
            ['collection_id' => $collection->id, 'reason_code' => $reason, 'explanation' => trim((string) $data['explanation']), 'confirmed' => true, 'confirm_cash_adjustment' => (bool) ($data['confirm_cash_adjustment'] ?? false)],
            fn (): array => $this->reverseLocked($collection, $data, $actor, $idempotencyKey, $reason),
            'route_post_conversion_collection_reversal',
        );
    }

    private function reverseLocked(RoutePostConversionCollection $input, array $data, User $actor, string $key, string $reason): array
    {
        return DB::transaction(function () use ($input, $data, $actor, $key, $reason): array {
            $locked = RoutePostConversionCollection::query()->whereKey($input->id)->lockForUpdate()->firstOrFail();
            $businessId = (int) $locked->business_id;
            $branchId = (int) $locked->branch_id;
            abort_unless($actor->is_active && (int) $actor->business_id === $businessId && (int) $actor->current_branch_id === $branchId, 403);
            if ($locked->status !== 'captured') {
                throw ValidationException::withMessages(['collection' => 'El cobro ya fue reversado.']);
            }
            $entry = RouteDeliveryBatchPreSale::query()->whereKey($locked->route_delivery_batch_pre_sale_id)->lockForUpdate()->firstOrFail();
            $sale = Sale::query()->whereKey($locked->sale_id)->where('business_id', $businessId)->where('branch_id', $branchId)->lockForUpdate()->firstOrFail();
            $payment = SalePayment::query()->where('sale_id', $sale->id)->where('route_post_conversion_collection_id', $locked->id)->lockForUpdate()->firstOrFail();
            if (SaleRefund::query()->where('sale_payment_id', $payment->id)->where('status', 'confirmed')->exists()) {
                throw ValidationException::withMessages(['payment' => 'Un pago ya reembolsado no puede convertirse después en una corrección de cobro.']);
            }
            if ($sale->payment_status !== 'paid' || $payment->status !== 'captured' || (string) $sale->amount_paid !== (string) $locked->amount || (string) $sale->total !== (string) $locked->amount) {
                throw ValidationException::withMessages(['sale' => 'La venta no es elegible para reversa total.']);
            }
            $items = RouteCashSettlementItem::query()->where('route_post_conversion_collection_id', $locked->id)->lockForUpdate()->get();
            $settlements = RouteCashSettlement::query()->whereIn('id', $items->pluck('route_cash_settlement_id'))->lockForUpdate()->get()->keyBy('id');
            if ($items->contains(fn (RouteCashSettlementItem $item) => $item->is_active && in_array($settlements->get($item->route_cash_settlement_id)?->status, ['draft', 'confirmed'], true))) {
                throw ValidationException::withMessages(['settlement' => 'El cobro está reservado o confirmado en una liquidación.']);
            }

            $cashType = $this->cashCorrectionType($locked, $data, $businessId, $branchId);
            $operationId = OperationIdempotencyKey::query()->where('business_id', $businessId)->where('branch_id', $branchId)->where('user_id', $actor->id)->where('operation_type', 'route_post_conversion_collection_reverse')->where('idempotency_key', $key)->value('id');
            $reversal = RoutePostConversionCollectionReversal::query()->create([
                'business_id' => $businessId, 'branch_id' => $branchId, 'route_post_conversion_collection_id' => $locked->id,
                'sale_payment_id' => $payment->id, 'reason_code' => $reason, 'explanation' => trim((string) $data['explanation']),
                'reversed_by' => $actor->id, 'reversed_at' => now()->startOfSecond(),
                'cash_correction_type' => $cashType === 'current_open_session_adjustment' ? 'none' : $cashType,
                'operation_idempotency_key_id' => $operationId,
            ]);
            if ($cashType === 'current_open_session_adjustment') {
                $session = CashRegister::requireOpenSession($businessId, 'La sesión original debe seguir siendo la caja abierta actual.', true, $branchId);
                $movement = CashRegister::recordMovement($session, 'route_post_conversion_collection_reversal_current_session', -(float) $locked->amount, 'route_post_conversion_collection_reversal', $reversal->id, "Reversa administrativa de cobro posterior #{$locked->id}", $actor->id);
                $reversal->update(['cash_correction_type' => $cashType, 'compensating_cash_movement_id' => $movement->id]);
            }
            $locked->update(['status' => 'reversed']);
            $payment->update(['status' => 'reversed']);
            $sale->update(['payment_status' => 'unpaid', 'amount_paid' => '0.00', 'credit_balance' => '0.00', 'is_credit_sale' => false, 'due_date' => null, 'payment_method' => null]);
            $entry->update(['payment_method' => $entry->agreed_payment_method_snapshot]);

            return ['result_id' => $reversal->id, 'response_payload' => ['reversal_id' => $reversal->id, 'collection_id' => $locked->id]];
        });
    }

    private function cashCorrectionType(RoutePostConversionCollection $collection, array $data, int $businessId, int $branchId): string
    {
        if ($collection->payment_method !== 'cash' || $collection->custody_status !== 'posted_to_branch_cash') return 'none';
        $movement = CashMovement::query()->whereKey($collection->cash_movement_id)->lockForUpdate()->first();
        if (! $movement) throw ValidationException::withMessages(['cash' => 'El efectivo posteado no conserva su movimiento original.']);
        $session = $movement->session()->lockForUpdate()->firstOrFail();
        if ($session->status === 'closed') return 'historical_closed_session_ledger';
        if ($session->status !== 'open' || (int) CashRegister::currentOpenSession($businessId, true, $branchId)?->id !== (int) $session->id) throw ValidationException::withMessages(['cash' => 'Sólo puede ajustarse la misma sesión original aún abierta.']);
        if (($data['confirm_cash_adjustment'] ?? false) !== true) throw ValidationException::withMessages(['confirm_cash_adjustment' => 'Debe confirmar el ajuste administrativo de caja actual.']);
        return 'current_open_session_adjustment';
    }
}
