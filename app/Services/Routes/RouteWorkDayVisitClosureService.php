<?php

namespace App\Services\Routes;

use App\Models\PreSale;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use Illuminate\Database\Eloquent\Builder;

class RouteWorkDayVisitClosureService
{
    public const AUTOMATIC_NO_SALE_REASON = 'Cierre automático de jornada: visita sin venta registrada';

    /** @return Builder<RouteVisit> */
    public function eligiblePendingVisits(RouteWorkDay $workDay): Builder
    {
        return RouteVisit::query()
            ->where('business_id', $workDay->business_id)
            ->where('route_work_day_id', $workDay->id)
            ->where('status', 'pending')
            ->whereDoesntHave('preSale', fn (Builder $query) => $query
                ->where('business_id', $workDay->business_id)
                ->where('status', '!=', PreSale::STATUS_CANCELLED));
    }

    public function eligiblePendingVisitsCount(RouteWorkDay $workDay): int
    {
        return $this->eligiblePendingVisits($workDay)->count();
    }

    public function resolveEligiblePendingVisits(RouteWorkDay $workDay): int
    {
        return $this->eligiblePendingVisits($workDay)
            ->lockForUpdate()
            ->update([
                'status' => 'without_sale',
                'no_sale_reason' => self::AUTOMATIC_NO_SALE_REASON,
                'no_sale_note' => null,
                'finished_at' => now(),
            ]);
    }
}
