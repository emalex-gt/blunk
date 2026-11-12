<?php

namespace App\Support;

use App\Models\CreditReceipt;
use App\Models\CreditReceiptLine;
use App\Models\StockReservation;
use App\Models\TenantSetting;
use App\Support\Inventory\ProductQuantityPolicy;

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
        $pending = bcsub(
            bcsub((string) $line->quantity, (string) $line->qty_invoiced, ProductQuantityPolicy::SCALE),
            (string) $line->qty_cancelled,
            ProductQuantityPolicy::SCALE,
        );
        $pending = bccomp($pending, '0', ProductQuantityPolicy::SCALE) < 0 ? '0' : $pending;
        $pendingTotal = self::roundMoney(bcmul((string) $line->unit_price, $pending, 8));
        $status = match (true) {
            bccomp($pending, '0', ProductQuantityPolicy::SCALE) === 0
                && bccomp((string) $line->qty_invoiced, '0', ProductQuantityPolicy::SCALE) > 0 => 'invoiced',
            bccomp($pending, '0', ProductQuantityPolicy::SCALE) === 0 => 'cancelled',
            bccomp((string) $line->qty_invoiced, '0', ProductQuantityPolicy::SCALE) > 0 => 'partially_invoiced',
            default => 'pending',
        };

        $line->update([
            'qty_reserved' => ProductQuantityPolicy::minimum($line->qty_reserved, $pending),
            'qty_pending' => $pending,
            'pending_total' => $pendingTotal,
            'status' => $status,
        ]);

        return $line->refresh();
    }

    public static function refreshReceipt(CreditReceipt $receipt): CreditReceipt
    {
        $receipt->loadMissing('lines');
        $pendingTotal = $receipt->lines()
            ->pluck('pending_total')
            ->reduce(fn (string $total, $amount): string => bcadd($total, (string) $amount, 2), '0');
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

    private static function roundMoney(string $amount): string
    {
        return bcdiv(bcadd($amount, '0.005', 8), '1', 2);
    }
}
