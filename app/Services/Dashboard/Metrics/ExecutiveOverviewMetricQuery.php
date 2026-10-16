<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\Sale;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Support\Permissions;
use Illuminate\Support\Carbon;

final class ExecutiveOverviewMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $salesAllowed = $context->hasModule('pos') && ($context->hasPermission(Permissions::POS_VIEW) || $context->hasPermission(Permissions::REPORTS_SALES_VIEW));
        $today = $salesAllowed ? $this->sales($context, $context->period(DashboardPeriod::TODAY)) : null;
        $previous = $salesAllowed ? $this->sales($context, $this->previousDay($context)) : null;
        $month = $salesAllowed ? $this->sales($context, $context->period(DashboardPeriod::THIS_MONTH)) : null;
        $previousMonth = $salesAllowed ? $this->sales($context, $this->previousComparableMonth($context)) : null;
        $stock = $context->hasModule('inventory') && $context->hasPermission(Permissions::INVENTORY_VIEW) ? (new StockAlertsMetricQuery)->resolve($context, $period) : null;
        $receivable = $context->hasModule('credits') && $context->hasPermission(Permissions::CREDITS_ACCOUNTS_VIEW) && $context->hasFeature('credit_sales') ? (new AccountsReceivableMetricQuery)->resolve($context, $period) : null;
        $margin = $context->hasModule('pos') && $context->hasPermission(Permissions::REPORTS_PROFIT_VIEW) ? (new EstimatedMarginMetricQuery)->resolve($context, $context->period(DashboardPeriod::TODAY)) : null;
        $cash = $context->hasModule('cash_register') && $context->hasPermission(Permissions::CASH_REGISTER_VIEW) ? (new CashCurrentMetricQuery)->resolve($context, $period) : null;
        $routesAllowed = $context->hasModule('routes') && $context->hasPermission(Permissions::ROUTES_VIEW) && $context->hasPermission(Permissions::ROUTES_PRE_SALES_ADMIN_VIEW) && $context->hasPermission(Permissions::ROUTES_DELIVERY_RUNS_VIEW);
        $routes = $routesAllowed ? collect(['open_work_days', 'pending_visits', 'operational_pre_sales', 'pending_delivery_stops'])->mapWithKeys(fn ($key) => [$key => (new RouteOperationsMetricQuery($key))->resolve($context, $period)['count']])->all() : null;
        $attention = $this->attention($stock, $receivable, $cash, $routes, $context);
        return ['sales_today' => $today ? [...$today, 'change_percent' => $this->change($today['total'], $previous['total'])] : null, 'sales_month' => $month ? ['total' => $month['total'], 'previous_total' => $previousMonth['total'], 'change_percent' => $this->change($month['total'], $previousMonth['total'])] : null, 'margin_today' => $margin, 'receivable' => $receivable, 'stock' => $stock, 'cash' => $cash, 'routes' => $routes, 'attention' => $attention, 'total_attention_count' => count($attention)];
    }
    private function sales(DashboardContext $context, DashboardPeriod $period): array { $s = Sale::query()->where('business_id', $context->business->id)->when($context->branchIdForScopedMetric(), fn ($q,$id) => $q->where('branch_id',$id))->where(fn($q)=>$q->where('status','completed')->orWhereNull('status'))->whereBetween('created_at',[$period->start,$period->end])->selectRaw('COUNT(*) count, COALESCE(SUM(total),0) total')->first(); $count=(int)$s->count; $total=(float)$s->total; return ['total'=>number_format($total,2,'.',''),'count'=>$count,'average_ticket'=>number_format($count?$total/$count:0,2,'.','')]; }
    private function previousDay(DashboardContext $c): DashboardPeriod { return DashboardPeriod::for(DashboardPeriod::TODAY,$c->timezone,now()->subDay()); }
    private function previousComparableMonth(DashboardContext $c): DashboardPeriod { $now=now()->timezone($c->timezone); $start=$now->copy()->subMonthNoOverflow()->startOfMonth(); $end=$start->copy()->addDays(min($now->day,$start->daysInMonth)-1)->endOfDay(); return DashboardPeriod::range(DashboardPeriod::CURRENT,$start,$end); }
    private function change(string $current,string $previous): ?float { return (float)$previous > 0 ? round((((float)$current-(float)$previous)/(float)$previous)*100,2) : null; }
    private function attention(?array $stock, ?array $receivable, ?array $cash, ?array $routes, DashboardContext $c): array { $items=[]; $stockHref=$c->hasPermission(Permissions::REPORTS_LOW_STOCK_VIEW)?route('reports.low-stock',absolute:false):null; if(($stock['out_of_stock_count']??0)>0)$items[]=['key'=>'stock.out','severity'=>'critical','title'=>"{$stock['out_of_stock_count']} productos sin stock",'detail'=>'Revisa el inventario.','href'=>$stockHref]; if(($stock['low_stock_count']??0)>0)$items[]=['key'=>'stock.low','severity'=>'warning','title'=>"{$stock['low_stock_count']} productos con stock bajo",'detail'=>'Revisa el inventario.','href'=>$stockHref]; if((float)($receivable['amount']??0)>0)$items[]=['key'=>'credits','severity'=>'warning','title'=>'Pendientes por cobrar','detail'=>'Q '.number_format((float)$receivable['amount'],2),'href'=>route('credits.accounts.index',absolute:false)]; if($cash && $cash['status']==='closed')$items[]=['key'=>'cash','severity'=>'info','title'=>'No hay una caja abierta','detail'=>'Revisa la operación de caja.','href'=>route('cash-register.index',absolute:false)]; foreach(['open_work_days'=>'Jornadas abiertas','pending_visits'=>'Visitas pendientes','pending_delivery_stops'=>'Entregas pendientes','operational_pre_sales'=>'Preventas operativas'] as $key=>$title)if(($routes[$key]??0)>0)$items[]=['key'=>"routes.$key",'severity'=>'warning','title'=>"{$routes[$key]} $title",'detail'=>'Revisa la operación de rutas.','href'=>route('routes.global-operations.index',absolute:false)]; usort($items,fn($a,$b)=>['critical'=>0,'warning'=>1,'info'=>2][$a['severity']]<=>['critical'=>0,'warning'=>1,'info'=>2][$b['severity']]); return array_slice($items,0,7); }
}
