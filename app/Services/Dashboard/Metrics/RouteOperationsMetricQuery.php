<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\PreSale;
use App\Models\RouteCashSettlement;
use App\Models\RouteDeliveryStop;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use InvalidArgumentException;

final class RouteOperationsMetricQuery implements DashboardMetricQuery
{
    public function __construct(private readonly string $metric)
    {
    }

    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        return match ($this->metric) {
            'open_work_days' => ['count' => $this->scoped(RouteWorkDay::query(), $context)->where('status', 'open')->count()],
            'pending_visits' => ['count' => $this->scoped(RouteVisit::query(), $context)->where('status', 'pending')->count()],
            'operational_pre_sales' => ['count' => $this->scoped(PreSale::query(), $context)
                ->whereNull('converted_sale_id')
                ->whereIn('status', [PreSale::STATUS_SUBMITTED, PreSale::STATUS_PROCESSING, PreSale::STATUS_PICKED])
                ->count()],
            'pending_delivery_stops' => ['count' => $this->scoped(RouteDeliveryStop::query(), $context)->where('status', 'pending')->count()],
            'effective_settlements' => $this->effectiveSettlements($context, $period),
            default => throw new InvalidArgumentException("Unsupported route dashboard metric [{$this->metric}]."),
        };
    }

    private function effectiveSettlements(DashboardContext $context, DashboardPeriod $period): array
    {
        $summary = $this->scoped(RouteCashSettlement::query(), $context)
            ->where('status', 'confirmed')
            ->whereBetween('confirmed_at', [$period->start, $period->end])
            ->selectRaw('COUNT(*) as settlement_count, COALESCE(SUM(received_amount), 0) as amount')
            ->first();

        return [
            'count' => (int) ($summary->settlement_count ?? 0),
            'amount' => number_format((float) ($summary->amount ?? 0), 2, '.', ''),
        ];
    }

    private function scoped($query, DashboardContext $context)
    {
        return $query
            ->where('business_id', $context->business->id)
            ->when($context->branchIdForScopedMetric(), fn ($query, int $branchId) => $query->where('branch_id', $branchId));
    }
}
