<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\StockReservation;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\Inventory\ProductQuantityPolicy;

final class ActiveReservationsMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $summary = StockReservation::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->where('status', 'active')
            ->selectRaw('COUNT(*) as reservation_count, COALESCE(SUM(quantity), 0) as quantity')
            ->first();

        return [
            'count' => (int) ($summary->reservation_count ?? 0),
            'detail' => ProductQuantityPolicy::canonical($summary->quantity ?? 0),
        ];
    }
}
