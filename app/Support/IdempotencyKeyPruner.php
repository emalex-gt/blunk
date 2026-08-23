<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IdempotencyKeyPruner
{
    public function prune(array $options): array
    {
        $days = max(1, (int) ($options['days'] ?? 30));
        $businessId = filled($options['business'] ?? null) ? (int) $options['business'] : null;

        if ($businessId && ! Business::query()->whereKey($businessId)->exists()) {
            throw new InvalidArgumentException('El --business indicado no existe.');
        }

        $cutoff = now()->subDays($days);
        $eligible = $this->eligibleQuery($businessId, $cutoff);
        $counts = (clone $eligible)
            ->select('status', 'operation_type', DB::raw('COUNT(*) as count'))
            ->groupBy('status', 'operation_type')
            ->orderBy('status')
            ->orderBy('operation_type')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'operation_type' => $row->operation_type,
                'count' => (int) $row->count,
            ])
            ->all();
        $oldProcessing = DB::table('operation_idempotency_keys')
            ->when($businessId, fn (Builder $query) => $query->where('business_id', $businessId))
            ->where('status', IdempotencyService::STATUS_PROCESSING)
            ->where('created_at', '<=', $cutoff)
            ->count();
        $confirmed = (bool) ($options['confirm'] ?? false) && ! (bool) ($options['dry_run'] ?? false);
        $deleted = $confirmed ? (clone $eligible)->delete() : 0;

        return [
            'days' => $days,
            'business_id' => $businessId,
            'cutoff' => $cutoff,
            'counts' => $counts,
            'eligible_count' => array_sum(array_column($counts, 'count')),
            'old_processing_count' => $oldProcessing,
            'confirmed' => $confirmed,
            'deleted' => $deleted,
        ];
    }

    private function eligibleQuery(?int $businessId, $cutoff): Builder
    {
        return DB::table('operation_idempotency_keys')
            ->when($businessId, fn (Builder $query) => $query->where('business_id', $businessId))
            ->whereIn('status', [IdempotencyService::STATUS_COMPLETED, IdempotencyService::STATUS_FAILED])
            ->where('created_at', '<=', $cutoff);
    }
}
