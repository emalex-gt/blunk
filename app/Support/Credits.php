<?php

namespace App\Support;

use App\Models\CreditReceipt;
use App\Models\CreditReceiptLine;
use App\Models\StockReservation;
use App\Models\TenantSetting;

class Credits
{
    public static function enabled(?int $businessId = null): bool
    {
        return self::salesEnabled($businessId);
    }

    public static function salesEnabled(?int $businessId = null): bool
    {
        $businessId ??= currentBusinessId();

        if (! $businessId || ! module_enabled('credits', $businessId)) {
            return false;
        }

        return (bool) TenantSetting::query()
            ->where('business_id', $businessId)
            ->value('enable_credit_sales');
    }

    public static function reservationsEnabled(?int $businessId = null): bool
    {
        $businessId ??= currentBusinessId();

        if (! $businessId || ! module_enabled('credits', $businessId)) {
            return false;
        }

        return (bool) TenantSetting::query()
            ->where('business_id', $businessId)
            ->value('enable_credit_reservations');
    }

    public static function reserveStockOnCreditReservations(?int $businessId = null): bool
    {
        $businessId ??= currentBusinessId();

        if (! $businessId) {
            return true;
        }

        if (! self::reservationsEnabled($businessId)) {
            return false;
        }

        $value = TenantSetting::query()
            ->where('business_id', $businessId)
            ->value('reserve_stock_on_credit_reservations');

        return $value === null ? true : (bool) $value;
    }

    public static function releaseReservationStock(int $businessId): void
    {
        CreditReceiptLine::query()
            ->where('business_id', $businessId)
            ->whereIn('status', ['pending', 'partially_invoiced'])
            ->where('qty_reserved', '>', 0)
            ->update(['qty_reserved' => 0]);

        StockReservation::query()
            ->where('business_id', $businessId)
            ->where('source_type', 'credit_receipt')
            ->where('status', 'active')
            ->update([
                'status' => 'released',
                'released_at' => now(),
            ]);
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
            'qty_reserved' => min((int) $line->qty_reserved, $pending),
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
