<?php

namespace App\Services\Routes;

use App\Models\RouteDeliveryRun;
use App\Models\RouteDeliveryStop;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteDeliveryStopService
{
    public function __construct(private readonly RouteCashOperationGuard $cash, private readonly RouteDeliveryCollectionService $collections) {}

    public function complete(RouteDeliveryStop $stop, array $data, User $actor, string $key): IdempotencyResult
    {
        $businessId = (int) $stop->business_id; $branchId = (int) $stop->branch_id;
        abort_unless((int) $actor->business_id === $businessId && (int) $actor->current_branch_id === $branchId && $actor->is_active, 403);
        abort_unless(Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_EXECUTE), 403);
        return app(IdempotencyService::class)->run($businessId, $branchId, $actor->id, 'route_delivery_stop_complete', $key, ['stop_id' => $stop->id, ...$data], function () use ($stop, $data, $actor, $businessId, $branchId) {
            return DB::transaction(function () use ($stop, $data, $actor, $businessId, $branchId) {
                abort_unless((int) BranchInventory::activeBranch($businessId)->id === $branchId, 403);
                $locked = RouteDeliveryStop::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($stop->id)->with('run')->lockForUpdate()->firstOrFail();
                abort_unless((int) $locked->run->delivery_user_id === (int) $actor->id, 403);
                if ($locked->run->status !== 'open' || $locked->status !== 'pending') throw ValidationException::withMessages(['stop' => 'La parada no está disponible para registrar resultado.']);
                $this->cash->requireOpen($businessId, $branchId, true);
                if ($locked->collection_responsibility_snapshot === 'pre_seller') {
                    $collectionKeys = ['amount', 'payment_method', 'collected_by', 'collected_at', 'reference', 'details', 'override_reason', 'receive_cash_in_current_session'];
                    if ((bool) ($data['collected'] ?? false) || array_intersect($collectionKeys, array_keys($data)) !== []) {
                        throw ValidationException::withMessages(['collected' => 'El cobro del preventista es sólo de lectura.']);
                    }
                }
                $outcome = DeliveryOutcomeRules::normalize((string) ($data['delivery_status'] ?? ''), $data['not_delivered_reason_code'] ?? $data['not_delivered_reason'] ?? null, $data['delivery_notes'] ?? $data['notes'] ?? null);
                $locked->update([
                    'status' => $outcome['delivery_status'],
                    'not_delivered_reason_code' => $outcome['not_delivered_reason_code'],
                    'delivery_notes' => $outcome['delivery_notes'],
                    'completed_by' => $actor->id,
                    'completed_at' => now(),
                ]);
                $collection = null;
                if ($locked->collection_responsibility_snapshot === 'delivery_agent' && (bool) ($data['collected'] ?? false)) {
                    $collection = $this->collections->captureFull($locked, $data, $actor);
                }
                return ['result_id' => $locked->id, 'response_payload' => ['stop_id' => $locked->id, 'collection_id' => $collection?->id]];
            });
        }, 'route_delivery_stop');
    }

    /**
     * Captures the post-sale collection separately from the physical outcome.
     * A completed stop is intentionally allowed to remain unpaid, so this is
     * not folded into complete() or inferred from delivery status.
     */
    public function collect(RouteDeliveryStop $stop, array $data, User $actor, string $key): IdempotencyResult
    {
        $businessId = (int) $stop->business_id;
        $branchId = (int) $stop->branch_id;
        abort_unless((int) $actor->business_id === $businessId && (int) $actor->current_branch_id === $branchId && $actor->is_active, 403);
        abort_unless(Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_EXECUTE), 403);

        return app(IdempotencyService::class)->run($businessId, $branchId, $actor->id, 'route_delivery_stop_collect', $key, ['stop_id' => $stop->id, ...$data], function () use ($stop, $data, $actor, $businessId, $branchId) {
            return DB::transaction(function () use ($stop, $data, $actor, $businessId, $branchId) {
                abort_unless((int) BranchInventory::activeBranch($businessId)->id === $branchId, 403);
                $locked = RouteDeliveryStop::query()
                    ->where('business_id', $businessId)
                    ->where('branch_id', $branchId)
                    ->whereKey($stop->id)
                    ->with(['run', 'deliveryCollection'])
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless((int) $locked->run->delivery_user_id === (int) $actor->id, 403);
                if ($locked->run->status !== 'open' || ! in_array($locked->status, ['delivered', 'not_delivered'], true)) {
                    throw ValidationException::withMessages(['stop' => 'El cobro sólo puede registrarse en una parada finalizada de una jornada abierta.']);
                }
                if ($locked->collection_responsibility_snapshot !== 'delivery_agent') {
                    throw ValidationException::withMessages(['collection' => 'El cobro del preventista es sólo de lectura.']);
                }
                if ($locked->deliveryCollection) {
                    throw ValidationException::withMessages(['collection' => 'La parada ya tiene un cobro registrado.']);
                }

                $this->cash->requireOpen($businessId, $branchId, true);
                $collection = $this->collections->captureFull($locked, $data, $actor);

                return ['result_id' => $locked->id, 'response_payload' => ['stop_id' => $locked->id, 'collection_id' => $collection->id]];
            });
        }, 'route_delivery_stop_collection');
    }
}
