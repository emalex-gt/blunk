<?php

namespace App\Support;

use App\Models\CreditReceipt;
use App\Models\CreditReceiptLine;
use App\Models\TenantSetting;

class Credits
{
    public static function enabled(?int $businessId = null): bool
    {
        $businessId ??= currentBusinessId();

        if (! $businessId || ! module_enabled('credits', $businessId)) {
            return false;
        }

        return (bool) TenantSetting::query()
            ->where('business_id', $businessId)
            ->value('enable_credit_sales');
    }

    public static function formatNumber(CreditReceipt|int|null $receipt): string
    {
        $number = $receipt instanceof CreditReceipt ? $receipt->receipt_number : $receipt;

        return 'CR-'.($number ?: '0');
    }

    public static function refreshLine(CreditReceiptLine $line): CreditReceiptLine
    {
        $pending = max(0, (int) $line->quantity - (int) $line->qty_invoiced - (int) $line->qty_cancelled);
        $pendingTotal = round($pending * (float) $line->unit_price, 2);
        $status = match (true) {
            $pending === 0 && (int) $line->qty_invoiced > 0 => 'invoiced',
            $pending === 0 => 'cancelled',
            (int) $line->qty_invoiced > 0 => 'partially_invoiced',
            default => 'pending',
        };

        $line->update([
            'qty_pending' => $pending,
            'pending_total' => $pendingTotal,
            'status' => $status,
        ]);

        return $line->refresh();
    }

    public static function refreshReceipt(CreditReceipt $receipt): CreditReceipt
    {
        $receipt->loadMissing('lines');
        $pendingTotal = round((float) $receipt->lines()->sum('pending_total'), 2);
        $hasPending = $receipt->lines()->where('qty_pending', '>', 0)->exists();
        $hasInvoiced = $receipt->lines()->where('qty_invoiced', '>', 0)->exists();
        $hasActiveLines = $receipt->lines()->where('status', '!=', 'cancelled')->exists();

        $status = match (true) {
            ! $hasActiveLines => 'cancelled',
            ! $hasPending => 'invoiced',
            $hasInvoiced => 'partially_invoiced',
            default => 'pending',
        };

        $receipt->update([
            'pending_total' => $pendingTotal,
            'status' => $status,
        ]);

        return $receipt->refresh();
    }
}
