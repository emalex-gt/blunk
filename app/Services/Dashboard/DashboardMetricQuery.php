<?php

namespace App\Services\Dashboard;

interface DashboardMetricQuery
{
    /** @return array<string, mixed> */
    public function resolve(DashboardContext $context, DashboardPeriod $period): array;
}
