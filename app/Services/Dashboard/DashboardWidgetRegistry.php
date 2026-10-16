<?php

namespace App\Services\Dashboard;

use App\Services\Dashboard\Metrics\CashCurrentMetricQuery;
use App\Services\Dashboard\Metrics\AccountsReceivableMetricQuery;
use App\Services\Dashboard\Metrics\ActiveReservationsMetricQuery;
use App\Services\Dashboard\Metrics\EstimatedMarginMetricQuery;
use App\Services\Dashboard\Metrics\SalesSummaryMetricQuery;
use App\Services\Dashboard\Metrics\StockAlertsMetricQuery;
use App\Services\Dashboard\Metrics\TopProductMetricQuery;
use App\Services\Dashboard\Metrics\RouteOperationsMetricQuery;
use App\Services\Dashboard\Metrics\RouteSellerWorkDayMetricQuery;
use App\Services\Dashboard\Metrics\RouteDeliveryRunMetricQuery;
use App\Services\Dashboard\Metrics\CashierWorkspaceMetricQuery;
use App\Services\Dashboard\Metrics\ExecutiveOverviewMetricQuery;
use App\Services\Dashboard\Metrics\BranchManagerOverviewMetricQuery;
use App\Services\Dashboard\Metrics\WarehouseOverviewMetricQuery;
use App\Support\Permissions;
use LogicException;

final class DashboardWidgetRegistry
{
    /** @return array<string, DashboardWidgetDefinition> */
    public static function all(): array
    {
        $definitions = [
            new DashboardWidgetDefinition(key: 'executive.overview', title: 'Resumen ejecutivo', type: 'executive_overview', period: DashboardPeriod::CURRENT, query: new ExecutiveOverviewMetricQuery(), requiredModules: ['pos'], requiredAnyPermissions: [Permissions::POS_VIEW, Permissions::REPORTS_SALES_VIEW]),
            new DashboardWidgetDefinition(key: 'branch_manager.overview', title: 'Operación de sucursal', type: 'branch_manager_overview', period: DashboardPeriod::CURRENT, query: new BranchManagerOverviewMetricQuery(), requiredModules: [], requiredAnyPermissions: []),
            new DashboardWidgetDefinition(key: 'warehouse.overview', title: 'Inventario', type: 'warehouse_overview', period: DashboardPeriod::CURRENT, query: new WarehouseOverviewMetricQuery(), requiredModules: ['inventory'], requiredPermissions: [Permissions::INVENTORY_VIEW]),
            new DashboardWidgetDefinition(
                key: 'sales.today',
                title: 'Ventas de hoy',
                type: 'sales_summary',
                period: DashboardPeriod::TODAY,
                query: new SalesSummaryMetricQuery(),
                requiredModules: ['pos'],
                requiredAnyPermissions: [Permissions::POS_VIEW, Permissions::REPORTS_SALES_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'sales.this_month',
                title: 'Ventas del mes',
                type: 'sales_summary',
                period: DashboardPeriod::THIS_MONTH,
                query: new SalesSummaryMetricQuery(),
                requiredModules: ['pos'],
                requiredAnyPermissions: [Permissions::POS_VIEW, Permissions::REPORTS_SALES_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'cash.current',
                title: 'Caja actual',
                type: 'cash_status',
                period: DashboardPeriod::TODAY,
                query: new CashCurrentMetricQuery(),
                requiredModules: ['cash_register'],
                requiredPermissions: [Permissions::CASH_REGISTER_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'cashier.workspace', title: 'Mi caja', type: 'cashier_workspace', period: DashboardPeriod::CURRENT,
                query: new CashierWorkspaceMetricQuery(), requiredModules: ['pos'], requiredPermissions: [Permissions::POS_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'stock.alerts',
                title: 'Alertas de stock',
                type: 'stock_alerts',
                period: DashboardPeriod::TODAY,
                query: new StockAlertsMetricQuery(),
                requiredModules: ['inventory'],
                requiredPermissions: [Permissions::INVENTORY_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'sales.top_product',
                title: 'Producto más vendido',
                type: 'top_product',
                period: DashboardPeriod::TODAY,
                query: new TopProductMetricQuery(),
                requiredModules: ['pos'],
                requiredAnyPermissions: [Permissions::POS_VIEW, Permissions::REPORTS_TOP_PRODUCTS_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'sales.estimated_margin',
                title: 'Margen estimado de hoy',
                type: 'money',
                period: DashboardPeriod::TODAY,
                query: new EstimatedMarginMetricQuery(),
                requiredModules: ['pos'],
                requiredPermissions: [Permissions::REPORTS_PROFIT_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'credits.receivable',
                title: 'Cuentas por cobrar',
                type: 'money',
                period: DashboardPeriod::CURRENT,
                query: new AccountsReceivableMetricQuery(),
                requiredModules: ['credits'],
                requiredPermissions: [Permissions::CREDITS_ACCOUNTS_VIEW],
                requiredFeatures: ['credit_sales'],
            ),
            new DashboardWidgetDefinition(
                key: 'stock.active_reservations',
                title: 'Reservas activas',
                type: 'count',
                period: DashboardPeriod::CURRENT,
                query: new ActiveReservationsMetricQuery(),
                requiredModules: ['inventory'],
                requiredPermissions: [Permissions::INVENTORY_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'routes.my_work_day', title: 'Mi jornada', type: 'route_work_day', period: DashboardPeriod::CURRENT,
                query: new RouteSellerWorkDayMetricQuery(), requiredModules: ['routes'], requiredPermissions: [Permissions::ROUTES_WORK],
            ),
            new DashboardWidgetDefinition(
                key: 'routes.my_delivery_run', title: 'Mis entregas', type: 'route_delivery_run', period: DashboardPeriod::CURRENT,
                query: new RouteDeliveryRunMetricQuery(), requiredModules: ['routes'], requiredPermissions: [Permissions::ROUTES_DELIVERY_RUNS_VIEW, Permissions::ROUTES_DELIVERY_RUNS_EXECUTE],
            ),
            new DashboardWidgetDefinition(
                key: 'routes.open_work_days', title: 'Jornadas abiertas', type: 'count', period: DashboardPeriod::CURRENT,
                query: new RouteOperationsMetricQuery('open_work_days'), requiredModules: ['routes'], requiredPermissions: [Permissions::ROUTES_VIEW, Permissions::ROUTES_PRE_SALES_ADMIN_VIEW, Permissions::ROUTES_DELIVERY_RUNS_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'routes.pending_visits', title: 'Visitas pendientes', type: 'count', period: DashboardPeriod::CURRENT,
                query: new RouteOperationsMetricQuery('pending_visits'), requiredModules: ['routes'], requiredPermissions: [Permissions::ROUTES_VIEW, Permissions::ROUTES_PRE_SALES_ADMIN_VIEW, Permissions::ROUTES_DELIVERY_RUNS_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'routes.operational_pre_sales', title: 'Preventas operativas', type: 'count', period: DashboardPeriod::CURRENT,
                query: new RouteOperationsMetricQuery('operational_pre_sales'), requiredModules: ['routes'], requiredPermissions: [Permissions::ROUTES_VIEW, Permissions::ROUTES_PRE_SALES_ADMIN_VIEW, Permissions::ROUTES_DELIVERY_RUNS_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'routes.pending_delivery_stops', title: 'Entregas pendientes', type: 'count', period: DashboardPeriod::CURRENT,
                query: new RouteOperationsMetricQuery('pending_delivery_stops'), requiredModules: ['routes'], requiredPermissions: [Permissions::ROUTES_VIEW, Permissions::ROUTES_PRE_SALES_ADMIN_VIEW, Permissions::ROUTES_DELIVERY_RUNS_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'routes.effective_settlements', title: 'Efectivo liquidado hoy', type: 'money', period: DashboardPeriod::TODAY,
                query: new RouteOperationsMetricQuery('effective_settlements'), requiredModules: ['routes'], requiredPermissions: [Permissions::ROUTES_VIEW, Permissions::ROUTES_PRE_SALES_ADMIN_VIEW, Permissions::ROUTES_DELIVERY_RUNS_VIEW],
            ),
        ];

        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->key])) {
                throw new LogicException("Duplicate dashboard widget key [{$definition->key}].");
            }

            $indexed[$definition->key] = $definition;
        }

        return $indexed;
    }
}
