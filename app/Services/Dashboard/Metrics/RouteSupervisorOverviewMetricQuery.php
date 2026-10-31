<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\PreSale;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use Illuminate\Support\Facades\DB;

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
            'seller_work_days' => $this->sellerWorkDays($context),
            'pre_sale_flow' => $this->preSaleFlow($context, $context->period(DashboardPeriod::TODAY)),
        ];
    }

    /** @return array{items:array<int,array<string,int|string|null>>,total_open_work_days:int,has_more:bool,href:string} */
    private function sellerWorkDays(DashboardContext $context): array
    {
        $visits = RouteVisit::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->select('route_work_day_id')
            ->selectRaw('COUNT(*) as total_visits')
            ->selectRaw("COALESCE(SUM(CASE WHEN status <> 'pending' THEN 1 ELSE 0 END), 0) as completed_visits")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending_visits")
            ->groupBy('route_work_day_id');
        $preSales = PreSale::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->select('route_work_day_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN status <> 'draft' THEN 1 ELSE 0 END), 0) as pre_sales_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status <> 'draft' THEN total ELSE 0 END), 0) as pre_sales_total")
            ->groupBy('route_work_day_id');
        $base = RouteWorkDay::query()
            ->where('route_work_days.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('route_work_days.branch_id', $branchId))
            ->where('route_work_days.status', 'open');
        $total = (clone $base)->count();
        $items = $base
            ->leftJoinSub($visits, 'visit_summary', 'visit_summary.route_work_day_id', '=', 'route_work_days.id')
            ->leftJoinSub($preSales, 'pre_sale_summary', 'pre_sale_summary.route_work_day_id', '=', 'route_work_days.id')
            ->leftJoin('users as sellers', 'sellers.id', '=', 'route_work_days.seller_id')
            ->leftJoin('route_zones', 'route_zones.id', '=', 'route_work_days.route_zone_id')
            ->leftJoin('branches', 'branches.id', '=', 'route_work_days.branch_id')
            ->orderBy('route_work_days.started_at')
            ->orderBy('route_work_days.id')
            ->limit(15)
            ->get([
                'route_work_days.id',
                'route_work_days.status',
                'sellers.name as seller_name',
                'route_zones.name as zone_name',
                'branches.name as branch_name',
                DB::raw('COALESCE(visit_summary.total_visits, 0) as total_visits'),
                DB::raw('COALESCE(visit_summary.completed_visits, 0) as completed_visits'),
                DB::raw('COALESCE(visit_summary.pending_visits, 0) as pending_visits'),
                DB::raw('COALESCE(pre_sale_summary.pre_sales_count, 0) as pre_sales_count'),
                DB::raw('COALESCE(pre_sale_summary.pre_sales_total, 0) as pre_sales_total'),
            ])
            ->map(fn ($workDay) => [
                'id' => (int) $workDay->id,
                'status' => (string) $workDay->status,
                'seller_name' => $workDay->seller_name,
                'zone_name' => $workDay->zone_name,
                'branch_name' => $workDay->branch_name,
                'total_visits' => (int) $workDay->total_visits,
                'completed_visits' => (int) $workDay->completed_visits,
                'pending_visits' => (int) $workDay->pending_visits,
                'pre_sales_count' => (int) $workDay->pre_sales_count,
                'pre_sales_total' => number_format((float) $workDay->pre_sales_total, 2, '.', ''),
            ])
            ->all();

        return [
            'items' => $items,
            'total_open_work_days' => $total,
            'has_more' => $total > count($items),
            'href' => route('routes.global-operations.index', absolute: false),
        ];
    }

    /** @return array{submitted:int,processing:int,picked:int,converted_today:int} */
    private function preSaleFlow(DashboardContext $context, DashboardPeriod $today): array
    {
        $summary = PreSale::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as submitted', [PreSale::STATUS_SUBMITTED])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as processing', [PreSale::STATUS_PROCESSING])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as picked', [PreSale::STATUS_PICKED])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? AND converted_sale_id IS NOT NULL AND converted_at BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM sales WHERE sales.id = pre_sales.converted_sale_id AND sales.business_id = pre_sales.business_id AND sales.branch_id = pre_sales.branch_id) THEN 1 ELSE 0 END), 0) as converted_today', [PreSale::STATUS_CONVERTED, $today->start, $today->end])
            ->first();

        return [
            'submitted' => (int) $summary->submitted,
            'processing' => (int) $summary->processing,
            'picked' => (int) $summary->picked,
            'converted_today' => (int) $summary->converted_today,
        ];
    }
}
