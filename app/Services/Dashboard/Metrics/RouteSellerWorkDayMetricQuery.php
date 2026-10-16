<?php

namespace App\Services\Dashboard\Metrics;

use App\Models\PreSale;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Services\Routes\RouteCashOperationGuard;
use App\Services\Routes\RoutePostConversionCollectionReader;
use App\Support\Permissions;

final class RouteSellerWorkDayMetricQuery implements DashboardMetricQuery
{
    public function resolve(DashboardContext $context, DashboardPeriod $period): array
    {
        $branch = $context->branch;

        if (! $branch) {
            return $this->withoutOpenWorkDay($context, 0, null);
        }

        $workDay = RouteWorkDay::query()
            ->where('business_id', $context->business->id)
            ->where('branch_id', $branch->id)
            ->where('seller_id', $context->user->id)
            ->where('status', 'open')
            ->with(['zone:id,name', 'branch:id,name'])
            ->oldest('id')
            ->first();

        if (! $workDay) {
            $assignedZoneCount = RouteZone::query()
                ->where('business_id', $context->business->id)
                ->where('branch_id', $branch->id)
                ->where('assigned_user_id', $context->user->id)
                ->where('is_active', true)
                ->count();

            return $this->withoutOpenWorkDay($context, $assignedZoneCount, (int) $branch->id);
        }

        $visits = RouteVisit::query()
            ->where('business_id', $context->business->id)
            ->where('branch_id', $branch->id)
            ->where('route_work_day_id', $workDay->id)
            ->where('seller_id', $context->user->id)
            ->selectRaw("COUNT(*) as total_visits, COALESCE(SUM(CASE WHEN status <> 'pending' THEN 1 ELSE 0 END), 0) as visited, COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending, COALESCE(SUM(CASE WHEN status = 'with_pre_sale' THEN 1 ELSE 0 END), 0) as with_pre_sale, COALESCE(SUM(CASE WHEN status = 'without_sale' THEN 1 ELSE 0 END), 0) as without_sale")
            ->first();
        $preSales = PreSale::query()
            ->where('business_id', $context->business->id)
            ->where('branch_id', $branch->id)
            ->where('route_work_day_id', $workDay->id)
            ->where('seller_id', $context->user->id)
            ->whereIn('status', [
                PreSale::STATUS_SUBMITTED,
                PreSale::STATUS_PROCESSING,
                PreSale::STATUS_PICKED,
                PreSale::STATUS_CONVERTED,
            ])
            ->selectRaw('COUNT(*) as pre_sales_count, COALESCE(SUM(total), 0) as pre_sales_total')
            ->first();
        $nextVisit = RouteVisit::query()
            ->where('business_id', $context->business->id)
            ->where('branch_id', $branch->id)
            ->where('route_work_day_id', $workDay->id)
            ->where('seller_id', $context->user->id)
            ->where('status', 'pending')
            ->with('customer:id,name,commercial_name,address,phone')
            ->orderByRaw('visit_order IS NULL, visit_order')
            ->orderBy('id')
            ->first();
        $cash = app(RouteCashOperationGuard::class)->status((int) $context->business->id, (int) $branch->id);

        return [
            'has_open_work_day' => true,
            'work_day' => [
                'id' => $workDay->id,
                'zone_name' => $workDay->zone?->name,
                'branch_name' => $workDay->branch?->name,
            ],
            'total_visits' => (int) ($visits->total_visits ?? 0),
            'visited' => (int) ($visits->visited ?? 0),
            'pending' => (int) ($visits->pending ?? 0),
            'with_pre_sale' => (int) ($visits->with_pre_sale ?? 0),
            'without_sale' => (int) ($visits->without_sale ?? 0),
            'pre_sales_count' => (int) ($preSales->pre_sales_count ?? 0),
            'pre_sales_total' => number_format((float) ($preSales->pre_sales_total ?? 0), 2, '.', ''),
            'next_visit' => $nextVisit ? [
                'id' => $nextVisit->id,
                'visit_order' => $nextVisit->visit_order,
                'customer_name' => $nextVisit->customer?->commercial_name ?: $nextVisit->customer?->name,
                'address' => $nextVisit->customer?->address,
                'phone' => $nextVisit->customer?->phone,
                'href' => route('routes.mobile.visits.show', $nextVisit, absolute: false),
            ] : null,
            'primary_action' => [
                'label' => 'Continuar ruta',
                'href' => route('routes.mobile.work-days.show', $workDay, absolute: false),
            ],
            'route_cash' => [
                'is_open' => $cash['is_open'],
                'message' => $cash['is_open'] ? 'Caja de ruta disponible.' : RouteCashOperationGuard::MESSAGE,
            ],
            'pending_collections' => $this->pendingCollections($context, (int) $branch->id),
        ];
    }

    private function withoutOpenWorkDay(DashboardContext $context, int $assignedZoneCount, ?int $branchId): array
    {
        return [
            'has_open_work_day' => false,
            'message' => 'No tienes una jornada abierta.',
            'assigned_zones_count' => $assignedZoneCount,
            'primary_action' => [
                'label' => 'Ver mis rutas',
                'href' => route('routes.mobile.zones', absolute: false),
            ],
            'route_cash' => null,
            'pending_collections' => $branchId ? $this->pendingCollections($context, $branchId) : null,
        ];
    }

    /** @return array{count:int,total:string,href:string}|null */
    private function pendingCollections(DashboardContext $context, int $branchId): ?array
    {
        if (! $context->hasPermission(Permissions::ROUTES_POST_CONVERSION_COLLECTIONS_COLLECT)
            || (int) $context->user->current_branch_id !== $branchId) {
            return null;
        }

        $pending = app(RoutePostConversionCollectionReader::class)->pendingForSeller($context->user);

        return [
            'count' => $pending->count(),
            'total' => number_format((float) $pending->sum('total'), 2, '.', ''),
            'href' => route('routes.mobile.post-conversion-collections.index', absolute: false),
        ];
    }
}
