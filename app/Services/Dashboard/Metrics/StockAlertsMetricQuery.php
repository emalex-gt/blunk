<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\Product;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;

final class StockAlertsMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $query = Product::query()->where('products.business_id', $context->business->id);

        if ($context->branchIdForScopedMetric()) {
            $query->leftJoin('product_branch_stocks as dashboard_stocks', function ($join) use ($context) {
                $join->on('dashboard_stocks.product_id', '=', 'products.id')
                    ->where('dashboard_stocks.business_id', '=', $context->business->id)
                    ->where('dashboard_stocks.branch_id', '=', $context->branchIdForScopedMetric());
            });
            $stock = 'COALESCE(dashboard_stocks.stock, 0)';
        } else {
            $stock = 'products.stock';
        }

        $summary = $query->selectRaw("SUM(CASE WHEN {$stock} > 0 AND {$stock} <= products.min_stock THEN 1 ELSE 0 END) as low_stock_count")
            ->selectRaw("SUM(CASE WHEN {$stock} <= 0 THEN 1 ELSE 0 END) as out_of_stock_count")
            ->first();

        return [
            'low_stock_count' => (int) ($summary->low_stock_count ?? 0),
            'out_of_stock_count' => (int) ($summary->out_of_stock_count ?? 0),
        ];
    }
}
