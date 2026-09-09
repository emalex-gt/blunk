<?php

namespace App\Services\Routes;

use App\Models\RouteDeliveryStop;
use App\Models\RouteExternalDeliveryReconciliationItem;
use App\Models\RoutePendingCollectionCase;
use App\Models\User;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoutePendingCollectionService
{
    public function __construct(
        private readonly RouteDeliveryCollectionService $collections,
        private readonly RoutePendingCollectionCaseService $cases,
    ) {
    }

    public function collect(RoutePendingCollectionCase $case, array $data, User $actor, string $key): IdempotencyResult
    {
        abort_unless(Permissions::userHas($actor, Permissions::ROUTES_PENDING_COLLECTIONS_COLLECT), 403);
        $this->assertActorScope($case, $actor);
        $historicalCollectedAt = array_key_exists('collected_at', $data);
        $payload = [
            ...$data,
            'collected_by' => $data['collected_by'] ?? $actor->id,
            'collected_at' => $data['collected_at'] ?? now()->toDateTimeString(),
            '_historical_collected_at' => $historicalCollectedAt,
        ];

        return app(IdempotencyService::class)->run(
            (int) $case->business_id,
            (int) $case->branch_id,
            (int) $actor->id,
            'route_pending_collection_collect',
            $key,
            ['case_id' => $case->id, ...$payload],
            function () use ($case, $payload, $actor) {
                return DB::transaction(function () use ($case, $payload, $actor) {
                    $lockedCase = RoutePendingCollectionCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
                    $this->assertActorScope($lockedCase, $actor);
                    if ($lockedCase->status !== 'open') {
                        throw ValidationException::withMessages(['case' => 'El pendiente ya no está abierto para cobro.']);
                    }
                    $source = $this->lockedDeliveredSource($lockedCase);
                    $collection = $this->collections->capturePostDeliveryFull($source, $payload, $actor);
                    $resolved = $this->cases->resolveFromCollection($lockedCase, $collection, $actor);

                    return [
                        'result_id' => $collection->id,
                        'response_payload' => ['collection_id' => $collection->id, 'case_id' => $resolved->id],
                    ];
                });
            },
            'route_delivery_collection',
        );
    }

    public function collectForEligibleSale(int $saleId, array $data, User $actor, string $key): IdempotencyResult
    {
        abort_unless(Permissions::userHas($actor, Permissions::ROUTES_PENDING_COLLECTIONS_COLLECT), 403);

        return DB::transaction(function () use ($saleId, $data, $actor, $key) {
            $case = $this->cases->ensureForEligibleCandidate($saleId, $actor);
            return $this->collect($case, $data, $actor, $key);
        });
    }

    private function lockedDeliveredSource(RoutePendingCollectionCase $case): RouteExternalDeliveryReconciliationItem|RouteDeliveryStop
    {
        if ($case->delivery_origin === 'external_reconciliation') {
            $item = RouteExternalDeliveryReconciliationItem::query()
                ->where('business_id', $case->business_id)
                ->where('branch_id', $case->branch_id)
                ->whereKey($case->route_external_delivery_reconciliation_item_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($item->delivery_tracking_snapshot !== 'external' || $item->collection_responsibility_snapshot !== 'delivery_agent' || $item->delivery_status !== 'delivered') {
                throw ValidationException::withMessages(['delivery' => 'La entrega original ya no es elegible para cobro posterior.']);
            }

            return $item;
        }

        $stop = RouteDeliveryStop::query()
            ->where('business_id', $case->business_id)
            ->where('branch_id', $case->branch_id)
            ->whereKey($case->route_delivery_stop_id)
            ->with('run')
            ->lockForUpdate()
            ->firstOrFail();
        if ($stop->delivery_tracking_snapshot !== 'in_app' || $stop->collection_responsibility_snapshot !== 'delivery_agent' || $stop->status !== 'delivered') {
            throw ValidationException::withMessages(['delivery' => 'La entrega original ya no es elegible para cobro posterior.']);
        }

        return $stop;
    }

    private function assertActorScope(RoutePendingCollectionCase $case, User $actor): void
    {
        abort_unless(
            $actor->is_active
            && (int) $actor->business_id === (int) $case->business_id
            && (int) $actor->current_branch_id === (int) $case->branch_id,
            403,
        );
    }
}
