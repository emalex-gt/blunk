<?php

namespace App\Support\Inventory;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final class DraftReservationLease
{
    public const TTL_MINUTES = 15;
    public const HEARTBEAT_SECONDS = 60;

    public static function cutoff(): CarbonInterface
    {
        return now()->subMinutes(self::TTL_MINUTES);
    }

    public static function isExpired(mixed $updatedAt): bool
    {
        if ($updatedAt === null) {
            return false;
        }

        $updatedAt = $updatedAt instanceof CarbonInterface ? $updatedAt : Carbon::parse($updatedAt);

        return $updatedAt->lt(self::cutoff());
    }

    public static function applyEffectivePreSaleReservationLease(
        mixed $query,
        string $reservationTable = 'stock_reservations',
        string $preSaleTable = 'pre_sales',
    ): mixed {
        return $query->where(function ($query) use ($reservationTable, $preSaleTable) {
            $query->where("{$preSaleTable}.status", '!=', 'draft')
                ->orWhere("{$reservationTable}.updated_at", '>=', self::cutoff());
        });
    }
}
