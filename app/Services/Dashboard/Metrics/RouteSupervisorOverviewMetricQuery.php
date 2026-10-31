<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\PreSale;
use App\Models\RouteCashSettlement;
use App\Models\RouteCashSettlementVariance;
use App\Models\RouteDeliveryStop;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\Permissions;
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
        $today = $context->period(DashboardPeriod::TODAY);
        $deliveryOverview = $this->deliveryOverview($context, $today);
        $settlementOverview = $this->settlementOverview($context, $today);

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
            'pre_sale_flow' => $this->preSaleFlow($context, $today),
            'delivery_overview' => $deliveryOverview,
            'settlement_overview' => $settlementOverview,
            'operational_alerts' => $this->operationalAlerts($deliveryOverview, $settlementOverview),
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

    /** @return array{pending_backlog:int,delivered_today:int,not_delivered_today:int,unpaid_delivered:array{count:int,amount:string},href:string} */
    private function deliveryOverview(DashboardContext $context, DashboardPeriod $today): array
    {
        $summary = RouteDeliveryStop::query()
            ->join('sales', function ($join) {
                $join->on('sales.id', '=', 'route_delivery_stops.sale_id')
                    ->whereColumn('sales.business_id', 'route_delivery_stops.business_id')
                    ->whereColumn('sales.branch_id', 'route_delivery_stops.branch_id');
            })
            ->where('route_delivery_stops.business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('route_delivery_stops.branch_id', $branchId))
            ->selectRaw("COUNT(*) FILTER (WHERE route_delivery_stops.status = 'pending') as pending_backlog")
            ->selectRaw("COUNT(*) FILTER (WHERE route_delivery_stops.status = 'delivered' AND route_delivery_stops.completed_at BETWEEN ? AND ?) as delivered_today", [$today->start, $today->end])
            ->selectRaw("COUNT(*) FILTER (WHERE route_delivery_stops.status = 'not_delivered' AND route_delivery_stops.completed_at BETWEEN ? AND ?) as not_delivered_today", [$today->start, $today->end])
            ->selectRaw("COUNT(*) FILTER (WHERE route_delivery_stops.status = 'delivered' AND sales.status = 'completed' AND sales.payment_status = 'unpaid') as unpaid_delivered_count")
            ->selectRaw("COALESCE(SUM(sales.total) FILTER (WHERE route_delivery_stops.status = 'delivered' AND sales.status = 'completed' AND sales.payment_status = 'unpaid'), 0) as unpaid_delivered_amount")
            ->first();

        return [
            'pending_backlog' => (int) $summary->pending_backlog,
            'delivered_today' => (int) $summary->delivered_today,
            'not_delivered_today' => (int) $summary->not_delivered_today,
            'unpaid_delivered' => [
                'count' => (int) $summary->unpaid_delivered_count,
                'amount' => number_format((float) $summary->unpaid_delivered_amount, 2, '.', ''),
            ],
            'href' => route('routes.delivery-runs.index', absolute: false),
        ];
    }

    /** @return array{confirmed_today:array{count:int,amount:string},draft_count:?int,open_variances:?array{count:int,shortages_amount:string,overages_amount:string},href:?string,variances_href:?string} */
    private function settlementOverview(DashboardContext $context, DashboardPeriod $today): array
    {
        $scope = RouteCashSettlement::query()
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId));
        $confirmed = (clone $scope)
            ->where('status', 'confirmed')
            ->whereBetween('confirmed_at', [$today->start, $today->end])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(received_amount), 0) as amount')
            ->first();
        $canViewSettlements = $context->hasPermission(Permissions::ROUTES_CASH_SETTLEMENTS_VIEW)
            || $context->hasPermission(Permissions::ROUTES_CASH_SETTLEMENTS_REVIEW);
        $canViewVariances = $context->hasPermission(Permissions::ROUTES_CASH_VARIANCES_VIEW);
        $draftCount = $canViewSettlements ? (clone $scope)->where('status', 'draft')->count() : null;
        $openVariances = null;
        if ($canViewVariances) {
            $variance = RouteCashSettlementVariance::query()
                ->where('business_id', $context->business->id)
                ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId))
                ->where('status', 'open')
                ->selectRaw('COUNT(*) as count')
                ->selectRaw('COALESCE(SUM(CASE WHEN difference_amount < 0 THEN ABS(difference_amount) ELSE 0 END), 0) as shortages_amount')
                ->selectRaw('COALESCE(SUM(CASE WHEN difference_amount > 0 THEN difference_amount ELSE 0 END), 0) as overages_amount')
                ->first();
            $openVariances = [
                'count' => (int) $variance->count,
                'shortages_amount' => number_format((float) $variance->shortages_amount, 2, '.', ''),
                'overages_amount' => number_format((float) $variance->overages_amount, 2, '.', ''),
            ];
        }

        return [
            'confirmed_today' => ['count' => (int) $confirmed->count, 'amount' => number_format((float) $confirmed->amount, 2, '.', '')],
            'draft_count' => $draftCount,
            'open_variances' => $openVariances,
            'href' => $canViewSettlements ? route('routes.cash-settlements.index', absolute: false) : null,
            'variances_href' => $canViewVariances ? route('routes.cash-settlement-variances.index', absolute: false) : null,
        ];
    }

    /** @param array{pending_backlog:int,delivered_today:int,not_delivered_today:int,unpaid_delivered:array{count:int,amount:string},href:string} $delivery */
    /** @param array{confirmed_today:array{count:int,amount:string},draft_count:?int,open_variances:?array{count:int,shortages_amount:string,overages_amount:string},href:?string,variances_href:?string} $settlements */
    /** @return array<int,array{key:string,severity:string,title:string,detail:string,href:?string}> */
    private function operationalAlerts(array $delivery, array $settlements): array
    {
        $alerts = [];
        if ($delivery['unpaid_delivered']['count'] > 0) {
            $alerts[] = ['key' => 'deliveries.unpaid', 'severity' => 'warning', 'title' => "{$delivery['unpaid_delivered']['count']} entrega(s) sin cobro", 'detail' => 'Entrega realizada con venta pendiente de cobro.', 'href' => $delivery['href']];
        }
        if ($delivery['not_delivered_today'] > 0) {
            $alerts[] = ['key' => 'deliveries.not_delivered', 'severity' => 'warning', 'title' => "{$delivery['not_delivered_today']} entrega(s) no realizada(s)", 'detail' => 'Requieren seguimiento de la jornada de hoy.', 'href' => $delivery['href']];
        }
        if (($settlements['draft_count'] ?? 0) > 0) {
            $alerts[] = ['key' => 'settlements.draft', 'severity' => 'info', 'title' => "{$settlements['draft_count']} liquidación(es) pendiente(s)", 'detail' => 'Hay borradores pendientes de confirmación.', 'href' => $settlements['href']];
        }
        if (($settlements['open_variances']['count'] ?? 0) > 0) {
            $alerts[] = ['key' => 'settlements.variances', 'severity' => 'critical', 'title' => "{$settlements['open_variances']['count']} diferencia(s) abierta(s)", 'detail' => 'Requieren revisión de caja.', 'href' => $settlements['variances_href']];
        }

        return $alerts;
    }
}
