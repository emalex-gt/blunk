<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\Sale;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;

final class SalesSummaryMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $sales = $this->sales($context, $period);
        $summary = (clone $sales)
            ->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(total), 0) as sales_total')
            ->first();
        $count = (int) ($summary->sales_count ?? 0);
        $total = (string) ($summary->sales_total ?? '0');
        $lastSale = (clone $sales)->latest('created_at')->value('created_at');
        $cancelledCount = $period->key === DashboardPeriod::TODAY
            ? Sale::query()
                ->where('business_id', $context->business->id)
                ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
                ->where('status', 'cancelled')
                ->whereBetween('cancelled_at', [$period->start, $period->end])
                ->count()
            : 0;

        return [
            'total' => number_format((float) $total, 2, '.', ''),
            'count' => $count,
            'average_ticket' => number_format($count > 0 ? ((float) $total / $count) : 0, 2, '.', ''),
            'last_sale_time' => $lastSale ? $lastSale->copy()->timezone($context->timezone)->format('H:i') : null,
            'cancelled_count' => $cancelledCount,
        ];
    }

    private function sales(DashboardContext $context, DashboardPeriod $period)
    {
        return Sale::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'))
            ->whereBetween('created_at', [$period->start, $period->end]);
    }
}
