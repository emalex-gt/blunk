<?php

namespace App\Services\Routes;

use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteExternalDeliveryReconciliation;
use App\Models\RouteExternalDeliveryReconciliationItem;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteExternalDeliveryReconciliationService
{
    public function __construct(
        private readonly ExternalDeliveryEligibility $eligibility,
        private readonly RouteDeliveryCollectionService $collections,
        private readonly RoutePendingCollectionCaseService $pendingCases,
    ) {
    }

    public function reconcileItem(RouteDeliveryBatch $batch, RouteDeliveryBatchPreSale $entry, array $data, User $actor): IdempotencyResult
    {
        $businessId = (int) $batch->business_id;
        $branchId = (int) $batch->branch_id;
        abort_unless((int) $entry->route_delivery_batch_id === (int) $batch->id && (int) $entry->sale_id > 0, 404);
        abort_unless((int) $actor->business_id === $businessId && (int) $actor->current_branch_id === $branchId && $actor->is_active, 403);
        abort_unless(Permissions::userHas($actor, Permissions::ROUTES_EXTERNAL_DELIVERY_RECONCILE), 403);
        $key = (string) ($data['idempotency_key'] ?? '');
        if ($key === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'La llave de idempotencia es obligatoria.']);
        }

        return app(IdempotencyService::class)->run(
            $businessId, $branchId, $actor->id, 'route_external_delivery_reconcile_item', $key, $data,
            function () use ($batch, $entry, $data, $actor, $businessId, $branchId) {
                return DB::transaction(function () use ($batch, $entry, $data, $actor, $businessId, $branchId) {
                    abort_unless((int) BranchInventory::activeBranch($businessId)->id === $branchId, 403);
                    $lockedBatch = RouteDeliveryBatch::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                    $lockedEntry = RouteDeliveryBatchPreSale::query()->where('route_delivery_batch_id', $lockedBatch->id)->whereKey($entry->id)->with(['batch', 'sale'])->lockForUpdate()->firstOrFail();
                    $context = $this->eligibility->forEntry($lockedEntry);
                    if (! $context['eligible']) {
                        throw ValidationException::withMessages(['reconciliation' => 'Revisión administrativa requerida para esta línea histórica.']);
                    }
                    $outcome = DeliveryOutcomeRules::normalize(
                        (string) ($data['delivery_status'] ?? ''),
                        $data['not_delivered_reason'] ?? null,
                        $data['notes'] ?? null,
                    );
                    $status = $outcome['delivery_status'];
                    $reason = $outcome['not_delivered_reason_code'];
                    $notes = $outcome['delivery_notes'];

                    $reconciliation = RouteExternalDeliveryReconciliation::query()->where('route_delivery_batch_id', $lockedBatch->id)->lockForUpdate()->first();
                    if (! $reconciliation) {
                        $reconciliation = RouteExternalDeliveryReconciliation::query()->create([
                            'business_id' => $businessId, 'branch_id' => $branchId, 'route_delivery_batch_id' => $lockedBatch->id,
                            'opened_by' => $actor->id, 'opened_at' => now(),
                        ]);
                    }
                    $item = RouteExternalDeliveryReconciliationItem::query()->where('route_delivery_batch_pre_sale_id', $lockedEntry->id)->lockForUpdate()->first();
                    if ($item) {
                        throw ValidationException::withMessages(['reconciliation' => 'La línea ya fue conciliada.']);
                    }
                    $item = RouteExternalDeliveryReconciliationItem::query()->create([
                        'business_id' => $businessId, 'branch_id' => $branchId, 'route_external_delivery_reconciliation_id' => $reconciliation->id,
                        'route_delivery_batch_pre_sale_id' => $lockedEntry->id, 'pre_sale_id' => $lockedEntry->pre_sale_id, 'sale_id' => $lockedEntry->sale_id,
                        'delivery_tracking_snapshot' => 'external', 'collection_responsibility_snapshot' => $context['responsibility'],
                        'delivery_status' => $status, 'not_delivered_reason' => $reason, 'notes' => $notes,
                        'reconciled_by' => $actor->id, 'reconciled_at' => now(),
                    ]);
                    $collection = null;
                    if ($context['responsibility'] === 'pre_seller' && (bool) ($data['collected'] ?? false)) {
                        throw ValidationException::withMessages(['collected' => 'El pago del preventista es sólo de lectura durante la conciliación.']);
                    }
                    if ($context['responsibility'] === 'delivery_agent' && (bool) ($data['collected'] ?? false)) {
                        $collection = $this->collections->captureFull($item, $data, $actor);
                    }
                    $this->pendingCases->syncDeliveredOutcome($item, $actor);
                    $total = RouteDeliveryBatchPreSale::query()->where('route_delivery_batch_id', $lockedBatch->id)->count();
                    $reconciled = RouteExternalDeliveryReconciliationItem::query()->where('route_external_delivery_reconciliation_id', $reconciliation->id)->count();
                    if ($reconciled === $total && ! $reconciliation->completed_at) {
                        $reconciliation->update(['completed_at' => now()]);
                    }

                    return ['result_id' => $item->id, 'response_payload' => ['item_id' => $item->id, 'collection_id' => $collection?->id, 'progress' => ['total' => $total, 'reconciled' => $reconciled, 'pending' => max($total - $reconciled, 0)]]];
                });
            },
            'route_external_delivery_reconciliation_item',
        );
    }
}
