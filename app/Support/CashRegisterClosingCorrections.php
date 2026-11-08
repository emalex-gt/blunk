<?php

namespace App\Support;

use App\Models\CashRegisterClosingCorrection;
use App\Models\CashRegisterSession;
use App\Models\Sale;

final class CashRegisterClosingCorrections
{
    public static function recordSaleCancellation(Sale $sale, ?CashRegisterSession $correctionSession, ?int $userId, ?string $reason): void
    {
        $sale->loadMissing('payments');

        $payments = $sale->capturedPayments()
            ->get(['id', 'method', 'amount', 'cash_register_session_id']);
        $originalSessionIds = $payments
            ->pluck('cash_register_session_id')
            ->filter()
            ->map(fn ($sessionId) => (int) $sessionId)
            ->unique()
            ->values();

        // A sale paid from more than one historical session cannot be assigned to
        // one closing without evidence. Keep it observable as unattributed.
        $originResolution = 'undetermined';
        $originalSessionId = null;

        if ($originalSessionIds->count() === 1) {
            $originalSessionId = $originalSessionIds->first();
            $originResolution = 'payment_session';
        } elseif ($originalSessionIds->isEmpty() && $sale->created_at) {
            $candidates = CashRegisterSession::query()
                ->where('business_id', $sale->business_id)
                ->where('branch_id', $sale->branch_id)
                ->where('opened_at', '<=', $sale->created_at)
                ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $sale->created_at))
                ->pluck('id');

            if ($candidates->count() === 1) {
                $originalSessionId = (int) $candidates->first();
                $originResolution = 'sale_created_in_session';
            } elseif ($candidates->count() > 1) {
                $originResolution = 'ambiguous_session_window';
            }
        } elseif ($originalSessionIds->count() > 1) {
            $originResolution = 'multiple_payment_sessions';
        }

        if ($originalSessionId && $correctionSession && $originalSessionId === (int) $correctionSession->id) {
            return;
        }

        $methods = $payments->pluck('method')->filter()->unique()->values();
        $paymentMethod = $methods->count() === 1 ? $methods->first() : ($methods->count() > 1 ? 'mixed' : null);
        $paymentBreakdown = $payments->map(fn ($payment) => [
            'method' => $payment->method,
            'amount' => (float) $payment->amount,
            'cash_register_session_id' => $payment->cash_register_session_id,
        ])->all();

        CashRegisterClosingCorrection::query()->firstOrCreate([
            'source_type' => 'sale_cancellation',
            'source_id' => $sale->id,
            'correction_type' => 'sale_cancellation',
        ], [
            'business_id' => $sale->business_id,
            'branch_id' => $sale->branch_id,
            'original_cash_register_session_id' => $originalSessionId,
            'correction_cash_register_session_id' => $correctionSession?->id,
            'sale_id' => $sale->id,
            'payment_method' => $paymentMethod,
            'amount' => -1 * (float) $sale->total,
            'occurred_at' => now(),
            'responsible_user_id' => $userId,
            'reason' => $reason,
            'metadata' => [
                'origin_resolution' => $originResolution,
                'payment_breakdown' => $paymentBreakdown,
                'cash_effect' => -1 * CashRegister::cashAmountFromPayments($payments),
            ],
        ]);
    }
}
