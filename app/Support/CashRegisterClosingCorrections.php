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

        $originalSessionIds = $sale->capturedPayments()
            ->whereNotNull('cash_register_session_id')
            ->pluck('cash_register_session_id')
            ->map(fn ($sessionId) => (int) $sessionId)
            ->unique()
            ->values();

        // A sale paid from more than one historical session cannot be assigned to
        // one closing without evidence. Keep it observable as unattributed.
        $originalSessionId = $originalSessionIds->count() === 1
            ? $originalSessionIds->first()
            : null;

        if ($originalSessionId && $correctionSession && $originalSessionId === (int) $correctionSession->id) {
            return;
        }

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
            'payment_method' => $sale->payments->first()?->method,
            'amount' => -1 * (float) $sale->total,
            'occurred_at' => now(),
            'responsible_user_id' => $userId,
            'reason' => $reason,
            'metadata' => ['cash_effect' => -1 * CashRegister::cashAmountFromPayments($sale->payments)],
        ]);
    }
}
