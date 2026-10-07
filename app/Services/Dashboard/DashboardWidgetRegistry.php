<?php

namespace App\Services\Dashboard;

use App\Services\Dashboard\Metrics\CashCurrentMetricQuery;
use App\Services\Dashboard\Metrics\EstimatedMarginMetricQuery;
use App\Services\Dashboard\Metrics\SalesSummaryMetricQuery;
use App\Services\Dashboard\Metrics\StockAlertsMetricQuery;
use App\Services\Dashboard\Metrics\TopProductMetricQuery;
use App\Support\Permissions;
use LogicException;

final class DashboardWidgetRegistry
{
    /** @return array<string, DashboardWidgetDefinition> */
    public static function all(): array
    {
        $definitions = [
            new DashboardWidgetDefinition(
                key: 'sales.today',
                title: 'Ventas de hoy',
                type: 'sales_summary',
                period: DashboardPeriod::TODAY,
                query: new SalesSummaryMetricQuery(),
                requiredModules: ['pos'],
                requiredPermissions: [Permissions::POS_VIEW],
            ),
            new DashboardWidgetDefinition(
                key: 'sales.this_month',
                title: 'Ventas del mes',
                type: 'sales_summary',
                period: DashboardPeriod::THIS_MONTH,
                query: new SalesSummaryMetricQuery(),
                requiredModules: ['pos'],
                requiredPermissions: [Permissions::POS_VIEW],
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
                requiredPermissions: [Permissions::POS_VIEW],
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
