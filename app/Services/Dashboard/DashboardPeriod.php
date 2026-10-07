<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class DashboardPeriod
{
    public const TODAY = 'today';
    public const THIS_MONTH = 'this_month';

    private function __construct(
        public readonly string $key,
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {
    }

    public static function for(string $key, string $timezone, ?Carbon $now = null): self
    {
        $now = ($now ?? now())->copy()->timezone($timezone);

        return match ($key) {
            self::TODAY => new self($key, $now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()),
            self::THIS_MONTH => new self($key, $now->copy()->startOfMonth()->utc(), $now->copy()->endOfMonth()->utc()),
            default => throw new InvalidArgumentException("Unsupported dashboard period [{$key}]."),
        };
    }
}
