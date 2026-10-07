<?php

namespace App\Services\Dashboard\Metrics;

use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use Illuminate\Support\Facades\DB;

final class EstimatedMarginMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $margin = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sale_items.business_id', $context->business->id)
            ->where('sales.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('sales.branch_id', $branchId))
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$period->start, $period->end])
            ->selectRaw('COALESCE(SUM((sale_items.unit_price - sale_items.unit_cost) * sale_items.quantity), 0) as margin')
            ->value('margin');

        return ['amount' => number_format((float) $margin, 2, '.', '')];
    }
}
