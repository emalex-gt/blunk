<?php

namespace App\Services\Routes;

use App\Models\RouteDeliveryCollection;
use App\Models\RouteDeliveryStop;
use App\Models\RouteExternalDeliveryReconciliationItem;
use App\Models\RoutePendingCollectionCase;
use App\Models\RoutePendingCollectionEvent;
use App\Models\Sale;
use App\Models\User;
use App\Models\OperationIdempotencyKey;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use App\Support\Permissions;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoutePendingCollectionCaseService
{
    private const EVENT_TYPES = ['note', 'contact', 'visit', 'promise', 'no_response', 'dispute'];

    public function syncDeliveredOutcome(RouteExternalDeliveryReconciliationItem|RouteDeliveryStop $source, User $actor): ?RoutePendingCollectionCase
    {
        $external = $source instanceof RouteExternalDeliveryReconciliationItem;
        $lockedSource = $external
            ? RouteExternalDeliveryReconciliationItem::query()->whereKey($source->id)->lockForUpdate()->firstOrFail()
            : RouteDeliveryStop::query()->whereKey($source->id)->with('run')->lockForUpdate()->firstOrFail();

        if (! $this->isEligibleSource($lockedSource, $external)) {
            return null;
        }

        $sale = Sale::query()
            ->where('business_id', $lockedSource->business_id)
            ->where('branch_id', $lockedSource->branch_id)
            ->whereKey($lockedSource->sale_id)
            ->lockForUpdate()
            ->firstOrFail();
        $sale->payments()->lockForUpdate()->get();

        if (! $this->isEligibleSale($sale) || RouteDeliveryCollection::query()->captured()
            ->where('business_id', $lockedSource->business_id)
            ->where('branch_id', $lockedSource->branch_id)
            ->where('sale_id', $sale->id)
            ->lockForUpdate()
            ->exists()) {
            return null;
        }

        $case = RoutePendingCollectionCase::query()
            ->where('sale_id', $sale->id)
            ->lockForUpdate()
            ->first();
        if ($case) {
            return $case;
        }

        return RoutePendingCollectionCase::query()->create([
            'business_id' => $lockedSource->business_id,
            'branch_id' => $lockedSource->branch_id,
            'sale_id' => $sale->id,
            'pre_sale_id' => $lockedSource->pre_sale_id,
            'route_delivery_batch_pre_sale_id' => $lockedSource->route_delivery_batch_pre_sale_id,
            'route_external_delivery_reconciliation_item_id' => $external ? $lockedSource->id : null,
            'route_delivery_stop_id' => $external ? null : $lockedSource->id,
            'delivery_origin' => $external ? 'external_reconciliation' : 'in_app_stop',
            'original_delivery_user_id' => $external ? $lockedSource->reconciled_by : $lockedSource->run?->delivery_user_id,
            'status' => 'open',
            'opened_at' => $external ? $lockedSource->reconciled_at : $lockedSource->completed_at,
        ]);
    }

    public function ensureForEligibleCandidate(int $saleId, User $actor): RoutePendingCollectionCase
    {
        return DB::transaction(function () use ($saleId, $actor) {
            abort_unless($actor->is_active && $actor->current_branch_id, 403);
            $sale = Sale::query()
                ->where('business_id', $actor->business_id)
                ->where('branch_id', $actor->current_branch_id)
                ->whereKey($saleId)
                ->lockForUpdate()
                ->firstOrFail();
            $existing = RoutePendingCollectionCase::query()->where('sale_id', $sale->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->status !== 'open') {
                    throw ValidationException::withMessages(['case' => 'La venta ya no tiene un pendiente de cobro operativo abierto.']);
                }

                return $existing;
            }

            $external = RouteExternalDeliveryReconciliationItem::query()
                ->where('business_id', $sale->business_id)
                ->where('branch_id', $sale->branch_id)
                ->where('sale_id', $sale->id)
                ->where('delivery_tracking_snapshot', 'external')
                ->where('collection_responsibility_snapshot', 'delivery_agent')
                ->where('delivery_status', 'delivered')
                ->lockForUpdate()
                ->first();
            if ($external) {
                return $this->syncDeliveredOutcome($external, $actor)
                    ?? throw ValidationException::withMessages(['case' => 'La venta ya no es elegible para seguimiento.']);
            }

            $stop = RouteDeliveryStop::query()
                ->where('business_id', $sale->business_id)
                ->where('branch_id', $sale->branch_id)
                ->where('sale_id', $sale->id)
                ->where('delivery_tracking_snapshot', 'in_app')
                ->where('collection_responsibility_snapshot', 'delivery_agent')
                ->where('status', 'delivered')
                ->with('run')
                ->lockForUpdate()
                ->first();

            if (! $stop) {
                throw ValidationException::withMessages(['case' => 'La venta no tiene una entrega elegible para seguimiento.']);
            }

            return $this->syncDeliveredOutcome($stop, $actor)
                ?? throw ValidationException::withMessages(['case' => 'La venta ya no es elegible para seguimiento.']);
        });
    }

    public function addEvent(RoutePendingCollectionCase $case, array $data, User $actor, string $key): IdempotencyResult
    {
        abort_unless(Permissions::userHas($actor, Permissions::ROUTES_PENDING_COLLECTIONS_MANAGE), 403);
        $this->assertActorScope($case, $actor);
        $type = (string) ($data['type'] ?? '');
        if (! in_array($type, self::EVENT_TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'El tipo de seguimiento no es válido.']);
        }
        $note = filled($data['note'] ?? null) ? trim((string) $data['note']) : null;
        if (in_array($type, ['note', 'promise', 'dispute'], true) && blank($note)) {
            throw ValidationException::withMessages(['note' => 'La nota es obligatoria para este seguimiento.']);
        }
        if (blank($data['occurred_at'] ?? null)) {
            throw ValidationException::withMessages(['occurred_at' => 'La fecha del seguimiento es obligatoria.']);
        }
        $occurredAt = Carbon::parse($data['occurred_at']);

        return app(IdempotencyService::class)->run(
            (int) $case->business_id,
            (int) $case->branch_id,
            (int) $actor->id,
            'route_pending_collection_event',
            $key,
            ['case_id' => $case->id, 'type' => $type, 'note' => $note, 'occurred_at' => $occurredAt->toIso8601String()],
            function () use ($case, $actor, $key, $type, $note, $occurredAt) {
                $locked = RoutePendingCollectionCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
                $this->assertActorScope($locked, $actor);
                if ($locked->status !== 'open') {
                    throw ValidationException::withMessages(['case' => 'Sólo un pendiente abierto puede recibir seguimiento.']);
                }
                $operation = OperationIdempotencyKey::query()
                    ->where('business_id', $locked->business_id)
                    ->where('branch_id', $locked->branch_id)
                    ->where('user_id', $actor->id)
                    ->where('operation_type', 'route_pending_collection_event')
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->firstOrFail();
                $event = RoutePendingCollectionEvent::query()->create([
                    'route_pending_collection_case_id' => $locked->id,
                    'business_id' => $locked->business_id,
                    'branch_id' => $locked->branch_id,
                    'type' => $type,
                    'note' => $note,
                    'occurred_at' => $occurredAt,
                    'recorded_by' => $actor->id,
                    'operation_idempotency_key_id' => $operation->id,
                ]);

                return ['result_id' => $event->id, 'response_payload' => ['event_id' => $event->id, 'case_id' => $locked->id]];
            },
            'route_pending_collection_event',
        );
    }

    public function addEventForEligibleSale(int $saleId, array $data, User $actor, string $key): IdempotencyResult
    {
        abort_unless(Permissions::userHas($actor, Permissions::ROUTES_PENDING_COLLECTIONS_MANAGE), 403);

        return DB::transaction(function () use ($saleId, $data, $actor, $key) {
            $case = $this->ensureForEligibleCandidate($saleId, $actor);
            return $this->addEvent($case, $data, $actor, $key);
        });
    }

    public function updateAssignment(RoutePendingCollectionCase $case, ?int $assignedTo, mixed $nextFollowUpAt, User $actor, string $key): RoutePendingCollectionCase
    {
        abort_unless(Permissions::userHas($actor, Permissions::ROUTES_PENDING_COLLECTIONS_MANAGE), 403);
        $this->assertActorScope($case, $actor);
        $next = $nextFollowUpAt === null ? null : Carbon::parse($nextFollowUpAt);

        $result = app(IdempotencyService::class)->run(
            (int) $case->business_id,
            (int) $case->branch_id,
            (int) $actor->id,
            'route_pending_collection_assignment',
            $key,
            ['case_id' => $case->id, 'assigned_to' => $assignedTo, 'next_follow_up_at' => $next?->toIso8601String()],
            function () use ($case, $actor, $assignedTo, $next) {
                $locked = RoutePendingCollectionCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
                $this->assertActorScope($locked, $actor);
                if ($locked->status !== 'open') {
                    throw ValidationException::withMessages(['case' => 'Sólo un pendiente abierto puede ser asignado.']);
                }
                if ($assignedTo !== null) {
                    $assignee = User::query()
                        ->where('business_id', $locked->business_id)
                        ->where('current_branch_id', $locked->branch_id)
                        ->where('is_active', true)
                        ->whereKey($assignedTo)
                        ->first();
                    if (! $assignee) {
                        throw ValidationException::withMessages(['assigned_to' => 'El responsable debe ser un usuario activo de la sucursal.']);
                    }
                }
                $locked->update(['assigned_to' => $assignedTo, 'next_follow_up_at' => $next]);

                return ['result_id' => $locked->id, 'response_payload' => ['case_id' => $locked->id]];
            },
            'route_pending_collection_case',
        );

        return RoutePendingCollectionCase::query()->whereKey($result->resultId)->firstOrFail();
    }

    public function resolveFromCollection(RoutePendingCollectionCase $case, RouteDeliveryCollection $collection, User $actor): RoutePendingCollectionCase
    {
        $locked = RoutePendingCollectionCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
        $this->assertActorScope($locked, $actor);
        if ($locked->status !== 'open') {
            throw ValidationException::withMessages(['case' => 'El pendiente ya no puede resolverse.']);
        }
        if (
            (int) $collection->business_id !== (int) $locked->business_id
            || (int) $collection->branch_id !== (int) $locked->branch_id
            || (int) $collection->sale_id !== (int) $locked->sale_id
            || $collection->sale?->payment_status !== 'paid'
        ) {
            throw ValidationException::withMessages(['collection' => 'El cobro no corresponde a este pendiente.']);
        }

        $locked->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $actor->id,
            'resolution_route_delivery_collection_id' => $collection->id,
        ]);

        return $locked->refresh();
    }

    public function synchronizePhysicalCorrection(RouteExternalDeliveryReconciliationItem|RouteDeliveryStop $source, User $actor, string $correctionReason): ?RoutePendingCollectionCase
    {
        $external = $source instanceof RouteExternalDeliveryReconciliationItem;
        $lockedSource = $external
            ? RouteExternalDeliveryReconciliationItem::query()->whereKey($source->id)->lockForUpdate()->firstOrFail()
            : RouteDeliveryStop::query()->whereKey($source->id)->with('run')->lockForUpdate()->firstOrFail();
        $case = RoutePendingCollectionCase::query()->where('sale_id', $lockedSource->sale_id)->lockForUpdate()->first();
        $delivered = $external ? $lockedSource->delivery_status === 'delivered' : $lockedSource->status === 'delivered';

        if (! $delivered) {
            if ($case && $case->status === 'open') {
                $case->update([
                    'status' => 'not_applicable',
                    'not_applicable_at' => now(),
                    'not_applicable_by' => $actor->id,
                    'not_applicable_reason' => trim($correctionReason),
                ]);
            }

            return $case?->refresh();
        }

        if ($case && $case->status === 'not_applicable') {
            $sale = Sale::query()->whereKey($lockedSource->sale_id)->lockForUpdate()->firstOrFail();
            $sale->payments()->lockForUpdate()->get();
            $hasCollection = RouteDeliveryCollection::query()->captured()->where('sale_id', $sale->id)->lockForUpdate()->exists();
            if ($this->isEligibleSource($lockedSource, $external) && $this->isEligibleSale($sale) && ! $hasCollection) {
                $case->update([
                    'status' => 'open',
                    'opened_at' => $external ? $lockedSource->reconciled_at : $lockedSource->completed_at,
                ]);
            }

            return $case->refresh();
        }

        if (! $case) {
            return $this->syncDeliveredOutcome($lockedSource, $actor);
        }

        return $case;
    }

    private function isEligibleSource(RouteExternalDeliveryReconciliationItem|RouteDeliveryStop $source, bool $external): bool
    {
        if ($source->collection_responsibility_snapshot !== 'delivery_agent') {
            return false;
        }

        if ($external) {
            return $source->delivery_tracking_snapshot === 'external'
                && $source->delivery_status === 'delivered'
                && $source->reconciled_at !== null;
        }

        return $source->delivery_tracking_snapshot === 'in_app'
            && $source->status === 'delivered'
            && $source->completed_at !== null
            && $source->run !== null;
    }

    private function isEligibleSale(Sale $sale): bool
    {
        return $sale->payment_status === 'unpaid'
            && (float) $sale->amount_paid === 0.0
            && ! $sale->is_credit_sale
            && (float) $sale->credit_balance === 0.0
            && ! $sale->capturedPayments()->exists();
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
