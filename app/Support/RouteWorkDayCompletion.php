<?php

namespace App\Support;

use App\Models\PreSale;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\User;

class RouteWorkDayCompletion
{
    public const FINAL_PRE_SALE_STATUSES = [
        PreSale::STATUS_CANCELLED,
        PreSale::STATUS_CONVERTED,
    ];

    public function refresh(RouteWorkDay $workDay, ?User $user = null): bool
    {
        $complete = $this->isComplete($workDay);

        if ($complete && $workDay->completed_at === null) {
            $workDay->forceFill([
                'completed_at' => now(),
                'completed_by' => $user?->id,
            ])->save();

            return true;
        }

        if (! $complete && $workDay->completed_at !== null) {
            $workDay->forceFill([
                'completed_at' => null,
                'completed_by' => null,
            ])->save();
        }

        return $complete;
    }

    public function isComplete(RouteWorkDay $workDay): bool
    {
        if ($workDay->status !== 'closed') {
            return false;
        }

        $hasPendingVisits = RouteVisit::query()
            ->where('business_id', $workDay->business_id)
            ->where('route_work_day_id', $workDay->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingVisits) {
            return false;
        }

        return ! PreSale::query()
            ->where('business_id', $workDay->business_id)
            ->where('route_work_day_id', $workDay->id)
            ->whereNotIn('status', self::FINAL_PRE_SALE_STATUSES)
            ->exists();
    }
}
