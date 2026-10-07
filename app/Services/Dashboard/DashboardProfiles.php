<?php

namespace App\Services\Dashboard;

use InvalidArgumentException;

final class DashboardProfiles
{
    /** @return array<int, string> */
    public static function widgetKeys(string $profile): array
    {
        return match ($profile) {
            'owner_simple' => [
                'sales.today',
                'sales.this_month',
                'cash.current',
                'stock.alerts',
                'sales.top_product',
                'sales.estimated_margin',
            ],
            default => throw new InvalidArgumentException("Unsupported dashboard profile [{$profile}]."),
        };
    }
}
