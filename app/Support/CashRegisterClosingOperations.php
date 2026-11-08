<?php

namespace App\Support;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\CustomerCreditPayment;
use App\Models\Purchase;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Commercial payment activity shown alongside a cash-register closing.
 *
 * This deliberately does not feed expected_cash: cash_movements remain the
 * physical-cash ledger.  It is an operational view of payments, including a
 * cautious, read-only reconstruction for rows created before session links
 * were persisted.
 */
class CashRegisterClosingOperations
{
    private const DETAIL_LIMIT = 200;

    public static function forSession(CashRegisterSession $session, string $methodFilter = 'all', string $operationFilter = 'all'): array
    {
        $session->loadMissing('openedBy');
        $from = $session->opened_at;
        $to = $session->closed_at ?? now();
        $sessions = CashRegisterSession::query()
            ->where('business_id', $session->business_id)
            ->where('branch_id', $session->branch_id)
            ->where('opened_at', '<=', $to)
            ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $from))
            ->get(['id', 'opened_at', 'closed_at']);

        $operations = collect()
            ->merge(self::salePayments($session, $from, $to))
            ->merge(self::creditPayments($session, $from, $to))
            ->merge(self::purchases($session, $from, $to))
            ->merge(self::refunds($session, $from, $to))
            ->merge(self::expenses($session));

        $included = collect();
        $incidents = collect();

        foreach ($operations as $operation) {
            if ($operation['cash_register_session_id'] !== null) {
                if ((int) $operation['cash_register_session_id'] === (int) $session->id) {
                    $operation['attribution'] = 'direct';
                    $included->push($operation);
                }

                continue;
            }

            $matches = $sessions->filter(fn (CashRegisterSession $candidate) => self::inSession($operation['occurred_at'], $candidate));
            if ($matches->count() === 1 && (int) $matches->first()->id === (int) $session->id) {
                $operation['attribution'] = 'reconstructed';
                $included->push($operation);
            } elseif ($matches->contains(fn (CashRegisterSession $candidate) => (int) $candidate->id === (int) $session->id)) {
                $operation['attribution'] = 'ambiguous';
                $operation['incident'] = 'No se asignó: coincide con más de una sesión de caja.';
                $incidents->push($operation);
            }
        }

        // Saldos del periodo: siempre se calculan con TODAS las operaciones, sin filtros ni límite visual.
        $summary = self::emptySummary();
        foreach ($included as $operation) {
            // Una compra pagada fuera de caja puede verse en el historial, pero NO es egreso de esta caja.
            if ($operation['kind'] === 'purchase' && ! ($operation['paid_from_cash'] ?? false)) {
                // Dato informativo por metodo de pago; NO representa un egreso de esta caja.
                $method = self::method($operation['method']);
                $summary[$method]['purchases_outside_cash'] = round(
                    $summary[$method]['purchases_outside_cash'] + abs((float) $operation['signed_amount']),
                    2,
                );
                continue;
            }
            $method = self::method($operation['method']);
            $bucket = match ($operation['kind']) {
                'sale' => 'sales',
                'sale_cancellation', 'refund' => (int) ($operation['original_cash_register_session_id'] ?? 0) === (int) $session->id
                    ? 'same_turn_cancellations'
                    : 'prior_period_corrections',
                'credit_payment', 'credit_payment_cancellation' => 'credit_payments',
                'purchase' => 'purchases',
                'expense', 'expense_cancel' => 'expenses',
            };
            $summary[$method][$bucket] = round($summary[$method][$bucket] + $operation['signed_amount'], 2);
            $summary[$method]['net'] = round($summary[$method]['net'] + $operation['signed_amount'], 2);
        }

        $validMethods = ['all', 'cash', 'card', 'check', 'transfer', 'other'];
        $validOperations = ['all', 'sale', 'sale_cancellation', 'refund', 'credit_payment', 'credit_payment_cancellation', 'purchase', 'expense', 'expense_cancel'];
        $methodFilter = in_array($methodFilter, $validMethods, true) ? $methodFilter : 'all';
        $operationFilter = in_array($operationFilter, $validOperations, true) ? $operationFilter : 'all';

        // Filtrar ANTES de aplicar el límite de 200; los filtros nunca afectan los totales.
        $matchesFilters = static fn (array $row): bool =>
            ($methodFilter === 'all' || self::method($row['method']) === $methodFilter)
            && ($operationFilter === 'all' || $row['kind'] === $operationFilter);
        $details = $included->filter($matchesFilters)->sortByDesc('occurred_at')->values();
        $filteredIncidents = $incidents->filter($matchesFilters)->sortByDesc('occurred_at');

        return [
            'by_method' => collect($summary)->map(fn (array $row, string $method) => ['method' => $method, ...$row])->values()->all(),
            'details' => $details->take(self::DETAIL_LIMIT)->values()->all(),
            'detail_total' => $details->count(),
            'has_more_details' => $details->count() > self::DETAIL_LIMIT,
            'incidents' => $filteredIncidents->take(self::DETAIL_LIMIT)->values()->all(),
            'filters' => ['method' => $methodFilter, 'operation' => $operationFilter],
        ];
    }

    private static function salePayments(CashRegisterSession $session, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return SalePayment::query()
            ->where('business_id', $session->business_id)
            ->where('status', 'captured')
            ->whereNull('route_pre_sale_collection_id')->whereNull('route_delivery_collection_id')
            ->whereNull('route_post_conversion_collection_id')->whereNull('route_immediate_paid_entry_id')
            ->where(function ($query) use ($session, $from, $to) {
                $query->where('cash_register_session_id', $session->id)
                    ->orWhereBetween('collected_at', [$from, $to])
                    ->orWhere(fn ($query) => $query->whereNull('collected_at')->whereBetween('created_at', [$from, $to]))
                    ->orWhereHas('sale', fn ($query) => $query->whereBetween('cancelled_at', [$from, $to]));
            })
            ->with(['sale:id,business_id,branch_id,business_number,customer_name,status,cancelled_at'])
            ->get()
            ->filter(fn (SalePayment $payment) => $payment->sale && (int) $payment->sale->branch_id === (int) $session->branch_id)
            ->flatMap(function (SalePayment $payment) use ($session, $from, $to) {
                $at = $payment->collected_at ?? $payment->created_at;
                $rows = [[
                    'id' => 'sale-payment-'.$payment->id, 'kind' => 'sale', 'label' => 'Venta cobrada', 'method' => $payment->method,
                    'signed_amount' => (float) $payment->amount, 'amount' => (float) $payment->amount, 'occurred_at' => $at,
                    'cash_register_session_id' => $payment->cash_register_session_id, 'original_cash_register_session_id' => $payment->cash_register_session_id, 'reference' => 'Venta '.format_sale_number($payment->sale),
                    'party' => $payment->sale->customer_name, 'attribution' => null,
                ]];

                if ($payment->sale->status === 'cancelled' && $payment->sale->cancelled_at && ! SaleRefund::query()->where('sale_payment_id', $payment->id)->exists()) {
                    $rows[] = [
                        'id' => 'sale-cancellation-'.$payment->id, 'kind' => 'sale_cancellation', 'label' => 'Anulación de venta', 'method' => $payment->method,
                        'signed_amount' => -1 * (float) $payment->amount, 'amount' => (float) $payment->amount, 'occurred_at' => $payment->sale->cancelled_at,
                        'cash_register_session_id' => null, 'original_cash_register_session_id' => $payment->cash_register_session_id, 'reference' => 'Venta '.format_sale_number($payment->sale),
                        'party' => $payment->sale->customer_name, 'attribution' => null,
                    ];
                }

                return $rows;
            });
    }

    private static function creditPayments(CashRegisterSession $session, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return CustomerCreditPayment::query()
            ->where('business_id', $session->business_id)->where('branch_id', $session->branch_id)
            ->where(function ($query) use ($session, $from, $to) {
                $query->where('cash_register_session_id', $session->id)
                    ->orWhereBetween('created_at', [$from, $to])
                    ->orWhereBetween('cancelled_at', [$from, $to]);
            })
            ->with('customer:id,name')->get()
            ->flatMap(function (CustomerCreditPayment $payment) {
                $rows = [];
                if (in_array($payment->status, ['completed', 'cancelled'], true)) {
                    $rows[] = ['id' => 'credit-payment-'.$payment->id, 'kind' => 'credit_payment', 'label' => 'Abono de cliente', 'method' => $payment->payment_method,
                        'signed_amount' => (float) $payment->amount, 'amount' => (float) $payment->amount, 'occurred_at' => $payment->created_at,
                        'cash_register_session_id' => $payment->cash_register_session_id, 'reference' => 'Abono #'.$payment->payment_number, 'party' => $payment->customer?->name, 'attribution' => null];
                }

                if ($payment->status === 'cancelled' && $payment->cancelled_at) {
                    $rows[] = ['id' => 'credit-payment-cancellation-'.$payment->id, 'kind' => 'credit_payment_cancellation', 'label' => 'Anulación de abono', 'method' => $payment->payment_method,
                        'signed_amount' => -1 * (float) $payment->amount, 'amount' => (float) $payment->amount, 'occurred_at' => $payment->cancelled_at,
                        // The cancellation is a separate historical event. Its former
                        // payment-session link must not force it into the original session.
                        'cash_register_session_id' => null, 'reference' => 'Abono #'.$payment->payment_number, 'party' => $payment->customer?->name, 'attribution' => null];
                }
                return $rows;
            });
    }

    private static function purchases(CashRegisterSession $session, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Purchase::query()->where('business_id', $session->business_id)->where('branch_id', $session->branch_id)
            ->where('status', 'completed')
            ->where(fn ($query) => $query->where('cash_register_session_id', $session->id)->orWhereBetween('created_at', [$from, $to]))
            ->with('supplier:id,name')->get()
            ->filter(fn (Purchase $purchase) => ! in_array(strtolower((string) $purchase->payment_method), ['credit', 'on_credit', 'account'], true))
            ->map(fn (Purchase $purchase) => ['id' => 'purchase-'.$purchase->id, 'kind' => 'purchase', 'label' => $purchase->paid_from_cash ? 'Compra pagada desde caja' : 'Compra pagada (fuera de caja)', 'method' => $purchase->payment_method ?: 'other',
                'signed_amount' => -1 * (float) $purchase->total, 'amount' => (float) $purchase->total, 'occurred_at' => $purchase->created_at,
                'paid_from_cash' => (bool) $purchase->paid_from_cash, 'cash_register_session_id' => $purchase->cash_register_session_id, 'reference' => 'Compra '.format_purchase_number($purchase), 'party' => $purchase->supplier?->name, 'attribution' => null]);
    }

    /** Gastos reales y anulaciones: eventos firmados del libro físico de caja. */
    private static function expenses(CashRegisterSession $session): Collection
    {
        return CashMovement::query()
            ->where('business_id', $session->business_id)
            ->where('branch_id', $session->branch_id)
            ->where('cash_register_session_id', $session->id)
            ->whereIn('type', ['expense', 'expense_cancel'])
            ->with('createdBy:id,name')
            ->get()
            ->map(fn (CashMovement $movement) => [
                'id' => 'cash-movement-'.$movement->id,
                'kind' => $movement->type,
                'label' => $movement->type === 'expense' ? 'Gasto de caja' : 'Anulación de gasto',
                'method' => 'cash',
                'signed_amount' => (float) $movement->amount,
                'amount' => abs((float) $movement->amount),
                'occurred_at' => $movement->created_at,
                'cash_register_session_id' => $session->id,
                'reference' => $movement->reference_id ? 'Gasto #'.$movement->reference_id : 'Movimiento #'.$movement->id,
                'party' => $movement->description,
                'created_by' => $movement->createdBy?->name,
                'attribution' => null,
            ]);
    }

    private static function refunds(CashRegisterSession $session, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return SaleRefund::query()->where('business_id', $session->business_id)->where('branch_id', $session->branch_id)
            ->where('status', 'confirmed')
            ->where(function ($query) use ($session, $from, $to) {
                $query->where('cash_register_session_id', $session->id)
                    ->orWhereBetween('refunded_at', [$from, $to])
                    ->orWhere(fn ($query) => $query->whereNull('refunded_at')->whereBetween('created_at', [$from, $to]));
            })->get()
            ->map(fn (SaleRefund $refund) => ['id' => 'refund-'.$refund->id, 'kind' => 'refund', 'label' => 'Devolución', 'method' => $refund->refund_method,
                'signed_amount' => -1 * (float) $refund->amount, 'amount' => (float) $refund->amount, 'occurred_at' => $refund->refunded_at ?? $refund->created_at,
                'cash_register_session_id' => $refund->cash_register_session_id, 'reference' => $refund->reference ?: 'Devolución #'.$refund->id, 'party' => $refund->refunded_to, 'attribution' => null]);
    }

    private static function inSession(CarbonInterface $at, CashRegisterSession $session): bool
    {
        $end = $session->closed_at ?? now();
        return $at->greaterThanOrEqualTo($session->opened_at) && $at->lessThanOrEqualTo($end);
    }

    private static function emptySummary(): array
    {
        return collect(['cash', 'card', 'check', 'transfer', 'other'])->mapWithKeys(fn ($method) => [$method => [
            'sales' => 0.0, 'same_turn_cancellations' => 0.0, 'prior_period_corrections' => 0.0, 'credit_payments' => 0.0, 'purchases' => 0.0, 'purchases_outside_cash' => 0.0, 'expenses' => 0.0, 'net' => 0.0,
        ]])->all();
    }

    private static function method(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'cash', 'efectivo' => 'cash', 'card', 'credit_card', 'debit_card', 'tarjeta' => 'card',
            'check', 'cheque' => 'check', 'transfer', 'bank_transfer', 'transferencia' => 'transfer', default => 'other',
        };
    }
}
