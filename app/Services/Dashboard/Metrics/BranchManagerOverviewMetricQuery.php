<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\Purchase;
use App\Models\Sale;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BranchManagerOverviewMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $salesAllowed = $context->hasModule('pos') && ($context->hasPermission(Permissions::POS_VIEW) || $context->hasPermission(Permissions::REPORTS_SALES_VIEW));
        $cashAllowed = $context->hasModule('cash_register') && $context->hasPermission(Permissions::CASH_REGISTER_VIEW);
        $inventoryAllowed = $context->hasModule('inventory') && $context->hasPermission(Permissions::INVENTORY_VIEW);
        $purchasesAllowed = $context->hasModule('purchases') && $context->hasPermission(Permissions::PURCHASES_VIEW);
        $routesAllowed = $context->hasModule('routes') && $context->hasPermission(Permissions::ROUTES_VIEW) && $context->hasPermission(Permissions::ROUTES_PRE_SALES_ADMIN_VIEW) && $context->hasPermission(Permissions::ROUTES_DELIVERY_RUNS_VIEW);
        $today = $context->period(DashboardPeriod::TODAY);
        $sales = $salesAllowed ? $this->sales($context, $today) : null;
        $previous = $salesAllowed ? $this->sales($context, $this->previousDay($context)) : null;
        $inventory = $inventoryAllowed ? (new StockAlertsMetricQuery())->resolve($context, $period) : null;
        $cash = $cashAllowed ? (new CashCurrentMetricQuery())->resolve($context, $period) : null;
        $routes = $routesAllowed ? $this->routes($context, $period) : null;
        $attention = $this->attention($context, $cash, $inventory, $routes);

        return [
            'sales_today' => $sales ? [...$sales, 'previous_day_total' => $previous['total'], 'previous_day_count' => $previous['count'], 'change_percent' => $this->change($sales['total'], $previous['total'])] : null,
            'sales_trend' => $salesAllowed ? $this->salesTrend($context) : [],
            'collections_today' => $salesAllowed ? $this->collections($context, $today) : null,
            'payment_mix' => $salesAllowed ? $this->paymentMix($context, $today) : [],
            'cash' => $cash,
            'inventory' => $inventory ? [...$inventory, 'reservations' => (new ActiveReservationsMetricQuery())->resolve($context, $period)] : null,
            'purchases_today' => $purchasesAllowed ? $this->purchases($context, $today) : null,
            'top_products_today' => $salesAllowed ? $this->topProducts($context, $today) : [],
            'routes' => $routes,
            'recent_activity' => $this->recentActivity($context, $salesAllowed, $purchasesAllowed),
            'attention' => $attention,
            'total_attention_count' => count($attention),
        ];
    }

    private function sales(DashboardContext $context, DashboardPeriod $period): array
    {
        $summary = $this->salesQuery($context, $period)->selectRaw('COUNT(*) as count, COALESCE(SUM(sales.total), 0) as total')->first();
        $count = (int) $summary->count;
        $total = (float) $summary->total;

        return ['total' => $this->money($total), 'count' => $count, 'average_ticket' => $this->money($count ? $total / $count : 0)];
    }

    private function salesTrend(DashboardContext $context): array
    {
        $end = now()->timezone($context->timezone)->endOfDay();
        $start = $end->copy()->subDays(6)->startOfDay();
        $period = DashboardPeriod::range(DashboardPeriod::CURRENT, $start, $end);
        $localDate = "DATE((sales.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?)";
        $rows = $this->salesQuery($context, $period)
            ->selectRaw("{$localDate} as local_date, COALESCE(SUM(sales.total), 0) as total, COUNT(*) as count", [$context->timezone])
            ->groupByRaw('1')
            ->get()
            ->keyBy('local_date');

        return collect(range(0, 6))->map(function (int $offset) use ($start, $rows) {
            $date = $start->copy()->addDays($offset);
            $row = $rows->get($date->toDateString());

            return ['date' => $date->toDateString(), 'label' => $date->locale('es')->translatedFormat('j M'), 'total' => round((float) ($row->total ?? 0), 2), 'count' => (int) ($row->count ?? 0)];
        })->all();
    }

    private function collections(DashboardContext $context, DashboardPeriod $period): array
    {
        $summary = $this->paymentsQuery($context, $period)->selectRaw('COUNT(*) as count, COALESCE(SUM(sale_payments.amount), 0) as total')->first();

        return ['total' => $this->money((float) $summary->total), 'count' => (int) $summary->count];
    }

    private function paymentMix(DashboardContext $context, DashboardPeriod $period): array
    {
        $rows = $this->paymentsQuery($context, $period)
            ->selectRaw('sale_payments.method, COUNT(*) as count, COALESCE(SUM(sale_payments.amount), 0) as total')
            ->groupBy('sale_payments.method')
            ->get()
            ->groupBy(fn ($row) => $this->paymentMethodKey((string) $row->method))
            ->map(fn ($payments, string $key) => ['method' => $key, 'label' => $this->paymentMethodLabel($key), 'total' => round((float) $payments->sum('total'), 2), 'count' => (int) $payments->sum('count')])
            ->sortByDesc('total')
            ->values();
        $total = (float) $rows->sum('total');

        return $rows->map(fn (array $row) => [...$row, 'percentage' => $total > 0 ? round(($row['total'] / $total) * 100, 2) : 0])->all();
    }

    private function purchases(DashboardContext $context, DashboardPeriod $period): array
    {
        $summary = Purchase::query()
            ->where('purchases.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('purchases.branch_id', $branchId))
            ->where(fn ($query) => $query->where('purchases.status', 'completed')->orWhereNull('purchases.status'))
            ->whereBetween('purchases.created_at', [$period->start, $period->end])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(purchases.total), 0) as total, COUNT(DISTINCT purchases.supplier_id) as supplier_count')
            ->first();

        return ['total' => $this->money((float) $summary->total), 'count' => (int) $summary->count, 'supplier_count' => (int) $summary->supplier_count];
    }

    private function topProducts(DashboardContext $context, DashboardPeriod $period): array
    {
        return DB::table('sale_items')
            ->join('sales', function ($join) { $join->on('sales.id', '=', 'sale_items.sale_id')->whereColumn('sales.business_id', 'sale_items.business_id'); })
            ->where('sale_items.business_id', $context->business->id)
            ->where('sales.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('sales.branch_id', $branchId))
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$period->start, $period->end])
            ->selectRaw('sale_items.product_id, MAX(sale_items.product_name) as name, COALESCE(SUM(sale_items.quantity), 0) as quantity, COALESCE(SUM(sale_items.total), 0) as total')
            ->groupBy('sale_items.product_id')
            ->orderByDesc('quantity')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['product_id' => (int) $row->product_id, 'name' => $row->name, 'quantity' => (float) $row->quantity, 'total' => (float) $row->total])
            ->all();
    }

    private function routes(DashboardContext $context, DashboardPeriod $period): array
    {
        return collect(['open_work_days', 'pending_visits', 'operational_pre_sales', 'pending_delivery_stops'])
            ->mapWithKeys(fn (string $key) => [$key => (new RouteOperationsMetricQuery($key))->resolve($context, $period)['count']])
            ->all();
    }

    private function recentActivity(DashboardContext $context, bool $salesAllowed, bool $purchasesAllowed): array
    {
        $activity = collect();
        $canOpenSales = $context->hasPermission(Permissions::SALES_VIEW);

        if ($salesAllowed) {
            $activity = $activity->concat(Sale::query()
                ->where('sales.business_id', $context->business->id)
                ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('sales.branch_id', $branchId))
                ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
                ->latest('sales.created_at')->limit(6)
                ->get(['id', 'business_id', 'business_number', 'customer_name', 'total', 'created_at'])
                ->map(fn (Sale $sale) => ['key' => "sale:{$sale->id}", 'type' => 'sale', 'title' => 'Venta #'.($sale->business_number ?? $sale->id), 'detail' => $sale->customer_name ?: 'Consumidor Final', 'amount' => (float) $sale->total, 'created_at' => $sale->created_at?->timezone($context->timezone)->toIso8601String(), 'href' => $canOpenSales ? route('sales.show', $sale, absolute: false) : null]));
        }

        if ($purchasesAllowed) {
            $activity = $activity->concat(Purchase::query()
                ->where('purchases.business_id', $context->business->id)
                ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('purchases.branch_id', $branchId))
                ->where(fn ($query) => $query->where('purchases.status', 'completed')->orWhereNull('purchases.status'))
                ->with('supplier:id,name')->latest('purchases.created_at')->limit(6)
                ->get(['id', 'business_id', 'supplier_id', 'business_number', 'purchase_number', 'total', 'created_at'])
                ->map(fn (Purchase $purchase) => ['key' => "purchase:{$purchase->id}", 'type' => 'purchase', 'title' => 'Compra #'.($purchase->business_number ?? $purchase->purchase_number ?? $purchase->id), 'detail' => $purchase->supplier?->name ?? 'Sin proveedor', 'amount' => (float) $purchase->total, 'created_at' => $purchase->created_at?->timezone($context->timezone)->toIso8601String(), 'href' => route('purchases.show', $purchase, absolute: false)]));
        }

        return $activity->sortByDesc('created_at')->take(8)->values()->all();
    }

    private function attention(DashboardContext $context, ?array $cash, ?array $inventory, ?array $routes): array
    {
        $items = [];
        $stockHref = $context->hasModule('reports') && $context->hasPermission(Permissions::REPORTS_LOW_STOCK_VIEW) ? route('reports.low-stock', absolute: false) : ($context->hasPermission(Permissions::INVENTORY_VIEW) ? route('stock.index', absolute: false) : null);
        if (($inventory['out_of_stock_count'] ?? 0) > 0) $items[] = $this->item('stock.out', 'critical', "{$inventory['out_of_stock_count']} productos sin stock", 'Revisa el inventario.', $stockHref);
        if (($inventory['low_stock_count'] ?? 0) > 0) $items[] = $this->item('stock.low', 'warning', "{$inventory['low_stock_count']} productos con stock bajo", 'Revisa el inventario.', $stockHref);
        if ($cash && $cash['status'] === 'closed') $items[] = $this->item('cash.closed', 'info', 'No hay una caja abierta en esta sucursal', 'Revisa la operación de caja.', route('cash-register.index', absolute: false));
        foreach (['open_work_days' => ['info', 'jornadas de ruta abiertas'], 'pending_visits' => ['warning', 'visitas pendientes'], 'operational_pre_sales' => ['warning', 'preventas requieren atención'], 'pending_delivery_stops' => ['warning', 'entregas pendientes']] as $key => [$severity, $label]) if (($routes[$key] ?? 0) > 0) $items[] = $this->item("routes.$key", $severity, "{$routes[$key]} $label", 'Revisa la operación de rutas.', route('routes.global-operations.index', absolute: false));
        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn (array $left, array $right) => $rank[$left['severity']] <=> $rank[$right['severity']] ?: $left['key'] <=> $right['key']);

        return array_slice($items, 0, 7);
    }

    private function salesQuery(DashboardContext $context, DashboardPeriod $period): Builder
    {
        return Sale::query()
            ->where('sales.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('sales.branch_id', $branchId))
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereBetween('sales.created_at', [$period->start, $period->end]);
    }

    private function paymentsQuery(DashboardContext $context, DashboardPeriod $period)
    {
        return DB::table('sale_payments')
            ->join('sales', function ($join) { $join->on('sales.id', '=', 'sale_payments.sale_id')->whereColumn('sales.business_id', 'sale_payments.business_id'); })
            ->where('sale_payments.business_id', $context->business->id)
            ->where('sales.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('sales.branch_id', $branchId))
            ->where('sale_payments.status', 'captured')
            ->where(fn ($query) => $query->where('sales.status', 'completed')->orWhereNull('sales.status'))
            ->whereRaw('COALESCE(sale_payments.collected_at, sale_payments.created_at) BETWEEN ? AND ?', [$period->start, $period->end]);
    }

    private function previousDay(DashboardContext $context): DashboardPeriod { return DashboardPeriod::for(DashboardPeriod::TODAY, $context->timezone, now()->subDay()); }
    private function paymentMethodKey(string $method): string { return match ($method) { 'cash', 'card', 'check' => $method, 'bank_transfer', 'transfer' => 'transfer', default => 'other' }; }
    private function paymentMethodLabel(string $method): string { return match ($method) { 'cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transferencia', 'check' => 'Cheque', default => 'Otro' }; }
    private function change(string $current, string $previous): ?float { return (float) $previous > 0 ? round((((float) $current - (float) $previous) / (float) $previous) * 100, 2) : null; }
    private function money(float $value): string { return number_format($value, 2, '.', ''); }
    private function item(string $key, string $severity, string $title, string $detail, ?string $href): array { return compact('key', 'severity', 'title', 'detail', 'href'); }
}
