<?php

namespace App\Services\Dashboard\Metrics;

use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\Permissions;

final class BranchManagerOverviewMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $canViewSales = $context->hasModule('pos')
            && ($context->hasPermission(Permissions::POS_VIEW) || $context->hasPermission(Permissions::REPORTS_SALES_VIEW));
        $sales = $canViewSales ? (new SalesSummaryMetricQuery())->resolve($context, $context->period(DashboardPeriod::TODAY)) : null;
        $previousDay = $canViewSales ? (new SalesSummaryMetricQuery())->resolve($context, DashboardPeriod::for(DashboardPeriod::TODAY, $context->timezone, now()->subDay())) : null;

        $cash = $context->hasModule('cash_register') && $context->hasPermission(Permissions::CASH_REGISTER_VIEW)
            ? (new CashCurrentMetricQuery())->resolve($context, $period)
            : null;
        $inventory = $context->hasModule('inventory') && $context->hasPermission(Permissions::INVENTORY_VIEW)
            ? (new StockAlertsMetricQuery())->resolve($context, $period)
            : null;
        $reservations = $inventory ? (new ActiveReservationsMetricQuery())->resolve($context, $period) : null;

        $canViewRoutes = $context->hasModule('routes')
            && $context->hasPermission(Permissions::ROUTES_VIEW)
            && $context->hasPermission(Permissions::ROUTES_PRE_SALES_ADMIN_VIEW)
            && $context->hasPermission(Permissions::ROUTES_DELIVERY_RUNS_VIEW);
        $routes = $canViewRoutes
            ? collect(['open_work_days', 'pending_visits', 'operational_pre_sales', 'pending_delivery_stops'])
                ->mapWithKeys(fn (string $key) => [$key => (new RouteOperationsMetricQuery($key))->resolve($context, $period)['count']])
                ->all()
            : null;

        $sales = $sales ? [
            ...$sales,
            'previous_day_total' => $previousDay['total'],
            'change_percent' => $this->change($sales['total'], $previousDay['total']),
        ] : null;

        $attention = $this->attention($context, $cash, $inventory, $routes);

        return [
            'sales' => $sales,
            'cash' => $cash,
            'inventory' => $inventory ? [...$inventory, 'reservations' => $reservations] : null,
            'routes' => $routes,
            'attention' => $attention,
            'total_attention_count' => count($attention),
            'actions' => $this->actions($context),
        ];
    }

    private function change(string $current, string $previous): ?float
    {
        return (float) $previous > 0
            ? round((((float) $current - (float) $previous) / (float) $previous) * 100, 2)
            : null;
    }

    private function attention(DashboardContext $context, ?array $cash, ?array $inventory, ?array $routes): array
    {
        $items = [];
        $stockHref = $context->hasModule('reports') && $context->hasPermission(Permissions::REPORTS_LOW_STOCK_VIEW)
            ? route('reports.low-stock', absolute: false)
            : ($context->hasPermission(Permissions::INVENTORY_VIEW) ? route('stock.index', absolute: false) : null);

        if (($inventory['out_of_stock_count'] ?? 0) > 0) {
            $items[] = $this->item('stock.out', 'critical', "{$inventory['out_of_stock_count']} productos sin stock", 'Revisa el inventario.', $stockHref);
        }
        if (($inventory['low_stock_count'] ?? 0) > 0) {
            $items[] = $this->item('stock.low', 'warning', "{$inventory['low_stock_count']} productos con stock bajo", 'Revisa el inventario.', $stockHref);
        }
        if ($cash && $cash['status'] === 'closed') {
            $items[] = $this->item('cash.closed', 'info', 'No hay una caja abierta en esta sucursal', 'Revisa la operación de caja.', route('cash-register.index', absolute: false));
        }
        foreach ([
            'open_work_days' => ['info', 'jornadas de ruta abiertas'],
            'pending_visits' => ['warning', 'visitas pendientes'],
            'operational_pre_sales' => ['warning', 'preventas requieren atención'],
            'pending_delivery_stops' => ['warning', 'entregas pendientes'],
        ] as $key => [$severity, $label]) {
            if (($routes[$key] ?? 0) > 0) {
                $items[] = $this->item("routes.$key", $severity, "{$routes[$key]} $label", 'Revisa la operación de rutas.', route('routes.global-operations.index', absolute: false));
            }
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn (array $left, array $right) => $rank[$left['severity']] <=> $rank[$right['severity']] ?: $left['key'] <=> $right['key']);

        return array_slice($items, 0, 7);
    }

    private function actions(DashboardContext $context): array
    {
        $definitions = [
            ['sales.create', 'Nueva venta', 'sales.create', 'primary', ['pos'], [Permissions::POS_VIEW]],
            ['cash-register.index', 'Ver caja', 'cash-register.index', 'secondary', ['cash_register'], [Permissions::CASH_REGISTER_VIEW]],
            ['stock.index', 'Inventario', 'stock.index', 'secondary', ['inventory'], [Permissions::INVENTORY_VIEW]],
            ['routes.global-operations', 'Operación de rutas', 'routes.global-operations.index', 'secondary', ['routes'], [Permissions::ROUTES_PRE_SALES_ADMIN_VIEW]],
            ['purchases.create', 'Nueva compra', 'purchases.create', 'primary', ['purchases'], [Permissions::PURCHASES_CREATE]],
            ['purchases.index', 'Ver compras', 'purchases.index', 'secondary', ['purchases'], [Permissions::PURCHASES_VIEW]],
            ['reports.sales', 'Reportes de ventas', 'reports.sales', 'secondary', ['reports'], [Permissions::REPORTS_SALES_VIEW]],
        ];

        return collect($definitions)
            ->filter(fn (array $definition) => collect($definition[4])->every(fn (string $module) => $context->hasModule($module))
                && collect($definition[5])->every(fn (string $permission) => $context->hasPermission($permission)))
            ->map(fn (array $definition) => [
                'key' => $definition[0],
                'label' => $definition[1],
                'href' => route($definition[2], absolute: false),
                'emphasis' => $definition[3],
            ])
            ->values()
            ->all();
    }

    private function item(string $key, string $severity, string $title, string $detail, ?string $href): array
    {
        return compact('key', 'severity', 'title', 'detail', 'href');
    }
}
