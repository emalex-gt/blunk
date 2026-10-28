<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ExecutiveOverviewMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $salesAllowed = $context->hasModule('pos') && ($context->hasPermission(Permissions::POS_VIEW) || $context->hasPermission(Permissions::REPORTS_SALES_VIEW));
        $purchasesAllowed = $context->hasModule('purchases') && $context->hasPermission(Permissions::PURCHASES_VIEW);
        $inventoryValueAllowed = $context->hasModule('inventory') && $context->hasPermission(Permissions::REPORTS_WAREHOUSE_MONEY_VIEW);
        $canViewSales = $context->hasPermission(Permissions::SALES_VIEW);
        $today = $salesAllowed ? $this->sales($context, $context->period(DashboardPeriod::TODAY)) : null;
        $previous = $salesAllowed ? $this->sales($context, $this->previousDay($context)) : null;
        $month = $salesAllowed ? $this->sales($context, $context->period(DashboardPeriod::THIS_MONTH)) : null;
        $previousMonth = $salesAllowed ? $this->sales($context, $this->previousComparableMonth($context)) : null;
        $stock = $context->hasModule('inventory') && $context->hasPermission(Permissions::INVENTORY_VIEW) ? (new StockAlertsMetricQuery)->resolve($context, $period) : null;
        $receivable = $context->hasModule('credits') && $context->hasPermission(Permissions::CREDITS_ACCOUNTS_VIEW) && $context->hasFeature('credit_sales') ? (new AccountsReceivableMetricQuery)->resolve($context, $period) : null;
        $margin = $context->hasModule('pos') && $context->hasPermission(Permissions::REPORTS_PROFIT_VIEW) ? (new EstimatedMarginMetricQuery)->resolve($context, $context->period(DashboardPeriod::TODAY)) : null;
        $cash = $context->hasModule('cash_register') && $context->hasPermission(Permissions::CASH_REGISTER_VIEW) ? (new CashCurrentMetricQuery)->resolve($context, $period) : null;
        $routesAllowed = $context->hasModule('routes') && $context->hasPermission(Permissions::ROUTES_VIEW) && $context->hasPermission(Permissions::ROUTES_PRE_SALES_ADMIN_VIEW) && $context->hasPermission(Permissions::ROUTES_DELIVERY_RUNS_VIEW);
        $routes = $routesAllowed ? collect(['open_work_days', 'pending_visits', 'operational_pre_sales', 'pending_delivery_stops'])->mapWithKeys(fn (string $key) => [$key => (new RouteOperationsMetricQuery($key))->resolve($context, $period)['count']])->all() : null;

        if ($margin) {
            $margin['percent'] = (float) ($today['total'] ?? 0) > 0 ? round(((float) $margin['amount'] / (float) $today['total']) * 100, 2) : null;
        }

        $attention = $this->attention($stock, $receivable, $cash, $routes, $context);

        return [
            'sales_today' => $today ? [...$today, 'change_percent' => $this->change($today['total'], $previous['total'])] : null,
            'sales_month' => $month ? [...$month, 'previous_total' => $previousMonth['total'], 'change_percent' => $this->change($month['total'], $previousMonth['total'])] : null,
            'margin_today' => $margin,
            'purchases_month' => $purchasesAllowed ? $this->purchases($context, $context->period(DashboardPeriod::THIS_MONTH)) : null,
            'inventory_value' => $inventoryValueAllowed ? $this->inventoryValue($context) : null,
            'sales_trend' => $salesAllowed ? $this->salesTrend($context) : [],
            'payment_mix' => $salesAllowed ? $this->paymentMix($context, $context->period(DashboardPeriod::THIS_MONTH)) : [],
            'top_products' => $salesAllowed && ($context->hasPermission(Permissions::PRODUCTS_VIEW) || $context->hasPermission(Permissions::REPORTS_TOP_PRODUCTS_VIEW)) ? $this->topProducts($context, $context->period(DashboardPeriod::THIS_MONTH)) : [],
            'top_customers' => $salesAllowed && $context->hasPermission(Permissions::REPORTS_SALES_BY_CUSTOMER_VIEW) ? $this->topCustomers($context, $context->period(DashboardPeriod::THIS_MONTH)) : [],
            'recent_activity' => $this->recentActivity($context, $canViewSales, $purchasesAllowed),
            'receivable' => $receivable,
            'stock' => $stock,
            'cash' => $cash,
            'routes' => $routes,
            'attention' => $attention,
            'total_attention_count' => count($attention),
        ];
    }

    private function sales(DashboardContext $context, DashboardPeriod $period): array
    {
        $summary = $this->salesQuery($context, $period)->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as total')->first();
        $count = (int) $summary->count;
        $total = (float) $summary->total;

        return ['total' => number_format($total, 2, '.', ''), 'count' => $count, 'average_ticket' => number_format($count ? $total / $count : 0, 2, '.', '')];
    }

    private function purchases(DashboardContext $context, DashboardPeriod $period): array
    {
        $summary = Purchase::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->where('status', 'completed')
            ->whereBetween('created_at', [$period->start, $period->end])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as total')
            ->first();
        $count = (int) $summary->count;
        $total = (float) $summary->total;

        return ['total' => number_format($total, 2, '.', ''), 'count' => $count, 'average' => number_format($count ? $total / $count : 0, 2, '.', '')];
    }

    private function inventoryValue(DashboardContext $context): array
    {
        $query = Product::query()->where('products.business_id', $context->business->id)->where('products.is_active', true);

        if ($context->branch?->id) {
            $query->leftJoin('product_branch_stocks as dashboard_stocks', function ($join) use ($context) {
                $join->on('dashboard_stocks.product_id', '=', 'products.id')
                    ->where('dashboard_stocks.business_id', '=', $context->business->id)
                    ->where('dashboard_stocks.branch_id', '=', $context->branch->id);
            });
            $stock = 'COALESCE(dashboard_stocks.stock, 0)';
        } else {
            $stock = 'products.stock';
        }

        $summary = $query->selectRaw("COUNT(*) as sku_count, COALESCE(SUM({$stock} * products.cost_price), 0) as amount")->first();

        return ['amount' => number_format((float) ($summary->amount ?? 0), 2, '.', ''), 'sku_count' => (int) ($summary->sku_count ?? 0)];
    }

    private function salesTrend(DashboardContext $context): array
    {
        $today = now()->timezone($context->timezone)->startOfDay();
        $start = $today->copy()->subDays(13);
        $period = DashboardPeriod::range(DashboardPeriod::CURRENT, $start, $today->copy()->endOfDay());
        $localDate = "DATE((sales.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?)";
        $rows = $this->salesQuery($context, $period)
            ->selectRaw("{$localDate} as local_date, COUNT(*) as count, COALESCE(SUM(sales.total), 0) as total", [$context->timezone])
            ->groupByRaw($localDate, [$context->timezone])
            ->get()
            ->keyBy('local_date');

        return collect(range(0, 13))->map(function (int $offset) use ($start, $rows) {
            $date = $start->copy()->addDays($offset);
            $key = $date->toDateString();
            $row = $rows->get($key);

            return [
                'date' => $key,
                'label' => mb_strtolower($date->locale('es')->translatedFormat('j M')),
                'total' => round((float) ($row->total ?? 0), 2),
                'count' => (int) ($row->count ?? 0),
            ];
        })->all();
    }

    private function paymentMix(DashboardContext $context, DashboardPeriod $period): array
    {
        $rows = $this->salesQuery($context, $period)
            ->selectRaw('payment_method, COUNT(*) as count, COALESCE(SUM(total), 0) as total')
            ->groupBy('payment_method')
            ->get()
            ->groupBy(fn ($row) => $this->paymentMethodKey((string) $row->payment_method))
            ->map(function ($methods, string $key) {
                return ['method' => $key, 'label' => $this->paymentMethodLabel($key), 'total' => round((float) $methods->sum('total'), 2), 'count' => (int) $methods->sum('count')];
            })
            ->sortByDesc('total')
            ->values();
        $total = (float) $rows->sum('total');

        return $rows->map(fn (array $row) => [...$row, 'percentage' => $total > 0 ? round(($row['total'] / $total) * 100, 2) : 0])->all();
    }

    private function topProducts(DashboardContext $context, DashboardPeriod $period): array
    {
        return DB::table('sale_items')
            ->join('sales', function ($join) {
                $join->on('sales.id', '=', 'sale_items.sale_id')->whereColumn('sales.business_id', 'sale_items.business_id');
            })
            ->where('sale_items.business_id', $context->business->id)
            ->where('sales.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('sales.branch_id', $branchId))
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$period->start, $period->end])
            ->selectRaw('sale_items.product_id, MAX(sale_items.product_name) as name, COALESCE(SUM(sale_items.quantity), 0) as quantity, COALESCE(SUM(sale_items.total), 0) as total')
            ->groupBy('sale_items.product_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['product_id' => $row->product_id, 'name' => $row->name, 'quantity' => (float) $row->quantity, 'total' => (float) $row->total])
            ->all();
    }

    private function topCustomers(DashboardContext $context, DashboardPeriod $period): array
    {
        return $this->salesQuery($context, $period)
            ->whereNotNull('customer_id')
            ->where('customer_name', '<>', 'Consumidor Final')
            ->selectRaw('customer_id, MAX(customer_name) as name, COUNT(*) as sales_count, COALESCE(SUM(total), 0) as total')
            ->groupBy('customer_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['customer_id' => $row->customer_id, 'name' => $row->name, 'sales_count' => (int) $row->sales_count, 'total' => (float) $row->total])
            ->all();
    }

    private function recentActivity(DashboardContext $context, bool $canViewSales, bool $canViewPurchases): array
    {
        $activity = collect();

        if ($canViewSales) {
            $activity = $activity->concat(Sale::query()
                ->where('business_id', $context->business->id)
                ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
                ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'))
                ->latest()
                ->limit(6)
                ->get(['id', 'business_number', 'customer_name', 'total', 'created_at'])
                ->map(fn (Sale $sale) => [
                    'key' => "sale:{$sale->id}",
                    'type' => 'sale',
                    'title' => 'Venta #'.($sale->business_number ?? $sale->id),
                    'detail' => $sale->customer_name ?: 'Consumidor Final',
                    'amount' => (float) $sale->total,
                    'at' => $sale->created_at?->timezone($context->timezone)->format('Y-m-d H:i'),
                    'href' => route('sales.show', $sale, absolute: false),
                ]));
        }

        if ($canViewPurchases) {
            $activity = $activity->concat(Purchase::query()
                ->where('business_id', $context->business->id)
                ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
                ->with('supplier:id,name')
                ->latest()
                ->limit(6)
                ->get(['id', 'business_id', 'supplier_id', 'business_number', 'purchase_number', 'total', 'created_at'])
                ->map(fn (Purchase $purchase) => [
                    'key' => "purchase:{$purchase->id}",
                    'type' => 'purchase',
                    'title' => 'Compra #'.($purchase->business_number ?? $purchase->purchase_number ?? $purchase->id),
                    'detail' => $purchase->supplier?->name ?? 'Sin proveedor',
                    'amount' => (float) $purchase->total,
                    'at' => $purchase->created_at?->timezone($context->timezone)->format('Y-m-d H:i'),
                    'href' => route('purchases.show', $purchase, absolute: false),
                ]));
        }

        return $activity->sortByDesc('at')->take(6)->values()->all();
    }

    private function salesQuery(DashboardContext $context, DashboardPeriod $period): Builder
    {
        return Sale::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->where(fn ($query) => $query->where('status', 'completed')->orWhereNull('status'))
            ->whereBetween('created_at', [$period->start, $period->end]);
    }

    private function previousDay(DashboardContext $context): DashboardPeriod
    {
        return DashboardPeriod::for(DashboardPeriod::TODAY, $context->timezone, now()->subDay());
    }

    private function previousComparableMonth(DashboardContext $context): DashboardPeriod
    {
        $now = now()->timezone($context->timezone);
        $start = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $end = $start->copy()->addDays(min($now->day, $start->daysInMonth) - 1)->endOfDay();

        return DashboardPeriod::range(DashboardPeriod::CURRENT, $start, $end);
    }

    private function change(string $current, string $previous): ?float
    {
        return (float) $previous > 0 ? round((((float) $current - (float) $previous) / (float) $previous) * 100, 2) : null;
    }

    private function paymentMethodKey(string $method): string
    {
        return match ($method) {
            'cash', 'card', 'check', 'credit' => $method,
            'bank_transfer', 'transfer' => 'transfer',
            default => 'other',
        };
    }

    private function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            'check' => 'Cheque',
            'credit' => 'Crédito',
            default => 'Otro',
        };
    }

    private function attention(?array $stock, ?array $receivable, ?array $cash, ?array $routes, DashboardContext $context): array
    {
        $items = [];
        $stockHref = $context->hasPermission(Permissions::REPORTS_LOW_STOCK_VIEW) ? route('reports.low-stock', absolute: false) : null;
        if (($stock['out_of_stock_count'] ?? 0) > 0) $items[] = ['key' => 'stock.out', 'severity' => 'critical', 'title' => "{$stock['out_of_stock_count']} productos sin stock", 'detail' => 'Revisa el inventario.', 'href' => $stockHref];
        if (($stock['low_stock_count'] ?? 0) > 0) $items[] = ['key' => 'stock.low', 'severity' => 'warning', 'title' => "{$stock['low_stock_count']} productos con stock bajo", 'detail' => 'Revisa el inventario.', 'href' => $stockHref];
        if ((float) ($receivable['amount'] ?? 0) > 0) $items[] = ['key' => 'credits', 'severity' => 'warning', 'title' => 'Pendientes por cobrar', 'detail' => 'Q '.number_format((float) $receivable['amount'], 2), 'href' => route('credits.accounts.index', absolute: false)];
        if ($cash && $cash['status'] === 'closed') $items[] = ['key' => 'cash', 'severity' => 'info', 'title' => 'No hay una caja abierta', 'detail' => 'Revisa la operación de caja.', 'href' => route('cash-register.index', absolute: false)];
        foreach (['open_work_days' => 'Jornadas abiertas', 'pending_visits' => 'Visitas pendientes', 'pending_delivery_stops' => 'Entregas pendientes', 'operational_pre_sales' => 'Preventas operativas'] as $key => $title) {
            if (($routes[$key] ?? 0) > 0) $items[] = ['key' => "routes.{$key}", 'severity' => 'warning', 'title' => "{$routes[$key]} {$title}", 'detail' => 'Revisa la operación de rutas.', 'href' => route('routes.global-operations.index', absolute: false)];
        }
        usort($items, fn (array $left, array $right) => ['critical' => 0, 'warning' => 1, 'info' => 2][$left['severity']] <=> ['critical' => 0, 'warning' => 1, 'info' => 2][$right['severity']]);

        return array_slice($items, 0, 7);
    }
}
