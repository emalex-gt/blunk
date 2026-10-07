<?php

namespace App\Services\Dashboard\Metrics;

use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\CashRegister;

final class CashCurrentMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $session = CashRegister::currentOpenSession(
            (int) $context->business->id,
            branchId: $context->branch?->id,
        );

        if (! $session) {
            return ['status' => 'closed', 'expected_cash' => null];
        }

        return [
            'status' => 'open',
            'expected_cash' => number_format((float) CashRegister::summary($session)['expected_cash'], 2, '.', ''),
        ];
    }
}
