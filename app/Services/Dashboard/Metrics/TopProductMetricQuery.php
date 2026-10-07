<?php

namespace App\Services\Dashboard\Metrics;

use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use Illuminate\Support\Facades\DB;

final class TopProductMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $product = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sale_items.business_id', $context->business->id)
            ->where('sales.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('sales.branch_id', $branchId))
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$period->start, $period->end])
            ->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->orderByDesc(DB::raw('SUM(sale_items.quantity)'))
            ->selectRaw('sale_items.product_name, SUM(sale_items.quantity) as quantity')
            ->first();

        return [
            'name' => $product?->product_name,
            'quantity' => $product ? (int) $product->quantity : 0,
        ];
    }
}
