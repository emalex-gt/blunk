<?php

namespace App\Services\Dashboard\Metrics;

use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;

final class RouteSupervisorOverviewMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $operations = [
            'open_work_days' => new RouteOperationsMetricQuery('open_work_days'),
            'pending_visits' => new RouteOperationsMetricQuery('pending_visits'),
            'operational_pre_sales' => new RouteOperationsMetricQuery('operational_pre_sales'),
            'pending_delivery_stops' => new RouteOperationsMetricQuery('pending_delivery_stops'),
            'effective_settlements' => new RouteOperationsMetricQuery('effective_settlements'),
        ];

        return [
            'open_work_days' => [
                'label' => 'Jornadas abiertas',
                'period' => 'current',
                ...$operations['open_work_days']->resolve($context, $period),
            ],
            'pending_visits' => [
                'label' => 'Visitas pendientes',
                'period' => 'backlog',
                ...$operations['pending_visits']->resolve($context, $period),
            ],
            'operational_pre_sales' => [
                'label' => 'Preventas operativas',
                'period' => 'backlog',
                ...$operations['operational_pre_sales']->resolve($context, $period),
            ],
            'pending_delivery_stops' => [
                'label' => 'Entregas pendientes',
                'period' => 'backlog',
                ...$operations['pending_delivery_stops']->resolve($context, $period),
            ],
            'effective_settlements' => [
                'label' => 'Efectivo liquidado',
                'period' => 'today',
                ...$operations['effective_settlements']->resolve($context, $period),
            ],
        ];
    }
}
