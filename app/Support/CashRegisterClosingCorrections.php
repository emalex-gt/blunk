<?php

namespace App\Support;

use App\Models\CashRegisterClosingCorrection;
use App\Models\CashRegisterSession;
use App\Models\Sale;

final class CashRegisterClosingCorrections
{
    public static function recordSaleCancellation(Sale $sale, CashRegisterSession $correctionSession, ?int $userId, ?string $reason): void
    {
        $originalSessionId = $sale->capturedPayments()
            ->whereNotNull('cash_register_session_id')
            ->value('cash_register_session_id');

        if (! $originalSessionId || (int) $originalSessionId === (int) $correctionSession->id) {
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
            'correction_cash_register_session_id' => $correctionSession->id,
            'sale_id' => $sale->id,
            'payment_method' => 'cash',
            'amount' => -1 * CashRegister::cashAmountFromPayments($sale->payments),
            'occurred_at' => now(),
            'responsible_user_id' => $userId,
            'reason' => $reason,
        ]);
    }
}
