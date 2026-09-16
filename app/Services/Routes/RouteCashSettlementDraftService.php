<?php

namespace App\Services\Routes;

use App\Models\RouteCashSettlement;
use App\Models\RouteCashSettlementItem;
use App\Models\RouteDeliveryCollection;
use App\Models\RoutePreSaleCollection;
use App\Models\RoutePostConversionCollection;
use App\Models\User;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteCashSettlementDraftService
{
    public function create(User $actor, int $collectorId, array $sources, string $key): IdempotencyResult
    {
        $businessId = (int) $actor->business_id;
        $branchId = (int) $actor->current_branch_id;
        $this->requireKey($key);

        return app(IdempotencyService::class)->run(
            $businessId, $branchId, $actor->id, 'route_cash_settlement_create', $key,
            ['collector_user_id' => $collectorId, 'sources' => $sources],
            function () use ($actor, $collectorId, $sources, $businessId, $branchId) {
                return DB::transaction(function () use ($actor, $collectorId, $sources, $businessId, $branchId) {
                    $collector = User::query()->whereKey($collectorId)->lockForUpdate()->firstOrFail();
                    $this->assertActorScope($actor, $businessId, $branchId);
                    $this->assertCollectorScope($collector, $businessId, $branchId);
                    $normalized = $this->normalizeSources($sources);
                    $collections = $this->lockEligibleCollections($normalized, $businessId, $branchId, $collectorId);

                    $settlement = RouteCashSettlement::query()->create([
                        'business_id' => $businessId,
                        'branch_id' => $branchId,
                        'collector_user_id' => $collectorId,
                        'recorded_by' => $actor->id,
                        'expected_amount' => 0,
                        'status' => 'draft',
                    ]);
                    $this->reserve($settlement, $normalized, $collections);
                    $this->recalculateExpected($settlement);

                    return ['result_id' => $settlement->id, 'response_payload' => ['settlement_id' => $settlement->id]];
                });
            },
            'route_cash_settlement',
        );
    }

    public function add(RouteCashSettlement $settlement, User $actor, array $sources, string $key): IdempotencyResult
    {
        $businessId = (int) $settlement->business_id;
        $branchId = (int) $settlement->branch_id;
        $this->requireKey($key);

        return app(IdempotencyService::class)->run(
            $businessId, $branchId, $actor->id, 'route_cash_settlement_add', $key,
            ['settlement_id' => $settlement->id, 'sources' => $sources],
            function () use ($settlement, $actor, $sources, $businessId, $branchId) {
                return DB::transaction(function () use ($settlement, $actor, $sources, $businessId, $branchId) {
                    $locked = $this->lockDraft($settlement, $actor, $businessId, $branchId);
                    $normalized = $this->normalizeSources($sources);
                    $collections = $this->lockEligibleCollections($normalized, $businessId, $branchId, (int) $locked->collector_user_id);
                    $this->reserve($locked, $normalized, $collections);
                    $this->recalculateExpected($locked);

                    return ['result_id' => $locked->id, 'response_payload' => ['settlement_id' => $locked->id]];
                });
            },
            'route_cash_settlement',
        );
    }

    public function remove(RouteCashSettlement $settlement, RouteCashSettlementItem $item, User $actor, string $key): IdempotencyResult
    {
        $businessId = (int) $settlement->business_id;
        $branchId = (int) $settlement->branch_id;
        $this->requireKey($key);

        return app(IdempotencyService::class)->run(
            $businessId, $branchId, $actor->id, 'route_cash_settlement_remove', $key,
            ['settlement_id' => $settlement->id, 'item_id' => $item->id],
            function () use ($settlement, $item, $actor, $businessId, $branchId) {
                return DB::transaction(function () use ($settlement, $item, $actor, $businessId, $branchId) {
                    $locked = $this->lockDraft($settlement, $actor, $businessId, $branchId);
                    $lockedItem = RouteCashSettlementItem::query()
                        ->where('route_cash_settlement_id', $locked->id)
                        ->whereKey($item->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    if (! $lockedItem->is_active) {
                        throw ValidationException::withMessages(['item' => 'El item ya fue retirado de la liquidación.']);
                    }

                    $lockedItem->update(['is_active' => false]);
                    $this->recalculateExpected($locked);

                    return ['result_id' => $locked->id, 'response_payload' => ['settlement_id' => $locked->id]];
                });
            },
            'route_cash_settlement',
        );
    }

    public function cancel(RouteCashSettlement $settlement, User $actor, string $reason, string $key): IdempotencyResult
    {
        $businessId = (int) $settlement->business_id;
        $branchId = (int) $settlement->branch_id;
        $this->requireKey($key);
        if (blank($reason)) {
            throw ValidationException::withMessages(['cancellation_reason' => 'El motivo de cancelación es obligatorio.']);
        }

        return app(IdempotencyService::class)->run(
            $businessId, $branchId, $actor->id, 'route_cash_settlement_cancel', $key,
            ['settlement_id' => $settlement->id, 'reason' => trim($reason)],
            function () use ($settlement, $actor, $reason, $businessId, $branchId) {
                return DB::transaction(function () use ($settlement, $actor, $reason, $businessId, $branchId) {
                    $locked = $this->lockDraft($settlement, $actor, $businessId, $branchId);
                    RouteCashSettlementItem::query()->where('route_cash_settlement_id', $locked->id)->where('is_active', true)->lockForUpdate()->update(['is_active' => false]);
                    $locked->update([
                        'expected_amount' => 0,
                        'received_amount' => null,
                        'difference_amount' => null,
                        'status' => 'cancelled',
                        'cancelled_by' => $actor->id,
                        'cancelled_at' => now(),
                        'cancellation_reason' => trim($reason),
                    ]);

                    return ['result_id' => $locked->id, 'response_payload' => ['settlement_id' => $locked->id]];
                });
            },
            'route_cash_settlement',
        );
    }

    private function lockDraft(RouteCashSettlement $settlement, User $actor, int $businessId, int $branchId): RouteCashSettlement
    {
        $this->assertActorScope($actor, $businessId, $branchId);
        $locked = RouteCashSettlement::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'draft') {
            throw ValidationException::withMessages(['settlement' => 'Sólo se puede modificar una liquidación en borrador.']);
        }

        return $locked;
    }

    /** @return array<int, array{origin:string, collection_id:int}> */
    private function normalizeSources(array $sources): array
    {
        if ($sources === []) {
            throw ValidationException::withMessages(['sources' => 'Seleccione al menos un cobro en custodia.']);
        }

        $normalized = [];
        foreach ($sources as $source) {
            $origin = match ($source['origin'] ?? null) {
                'pre_sale_collection', 'route_pre_sale_collection' => 'pre_sale_collection',
                'delivery_collection', 'route_delivery_collection' => 'delivery_collection',
                'post_conversion_collection', 'route_post_conversion_collection' => 'post_conversion_collection',
                default => throw ValidationException::withMessages(['sources' => 'El origen del cobro no es válido.']),
            };
            $id = (int) ($source['collection_id'] ?? $source['id'] ?? 0);
            if ($id <= 0) {
                throw ValidationException::withMessages(['sources' => 'El cobro seleccionado no es válido.']);
            }
            $normalized[] = ['origin' => $origin, 'collection_id' => $id];
        }

        usort($normalized, fn (array $left, array $right) => [$left['origin'], $left['collection_id']] <=> [$right['origin'], $right['collection_id']]);
        foreach ($normalized as $index => $source) {
            if ($index > 0 && $source === $normalized[$index - 1]) {
                throw ValidationException::withMessages(['sources' => 'Un cobro no puede seleccionarse más de una vez.']);
            }
        }

        return $normalized;
    }

    /** @return array{pre_sale_collection: Collection<int, RoutePreSaleCollection>, delivery_collection: Collection<int, RouteDeliveryCollection>, post_conversion_collection: Collection<int, RoutePostConversionCollection>} */
    private function lockEligibleCollections(array $sources, int $businessId, int $branchId, int $collectorId): array
    {
        $preIds = collect($sources)->where('origin', 'pre_sale_collection')->pluck('collection_id')->all();
        $deliveryIds = collect($sources)->where('origin', 'delivery_collection')->pluck('collection_id')->all();
        $postIds = collect($sources)->where('origin', 'post_conversion_collection')->pluck('collection_id')->all();
        $pre = RoutePreSaleCollection::query()->whereIn('id', $preIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $delivery = RouteDeliveryCollection::query()->captured()->whereIn('id', $deliveryIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $post = RoutePostConversionCollection::query()->captured()->whereIn('id', $postIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($pre->count() !== count($preIds) || $delivery->count() !== count($deliveryIds) || $post->count() !== count($postIds)) {
            throw ValidationException::withMessages(['sources' => 'Uno de los cobros seleccionados ya no existe.']);
        }

        foreach ($sources as $source) {
            $collection = match ($source['origin']) { 'pre_sale_collection' => $pre->get($source['collection_id']), 'delivery_collection' => $delivery->get($source['collection_id']), default => $post->get($source['collection_id']) };
            if ((int) $collection->business_id !== $businessId || (int) $collection->branch_id !== $branchId || (int) $collection->collected_by !== $collectorId) {
                throw ValidationException::withMessages(['sources' => 'El cobro no corresponde al cobrador o sucursal de esta liquidación.']);
            }
            if ($collection->payment_method !== 'cash' || $collection->custody_status !== 'held_by_collector' || (float) $collection->amount <= 0) {
                throw ValidationException::withMessages(['sources' => 'Sólo se puede liquidar efectivo que sigue bajo custodia del cobrador.']);
            }
            $reserved = RouteCashSettlementItem::query()
                ->where('is_active', true)
                ->when($source['origin'] === 'pre_sale_collection', fn ($query) => $query->where('route_pre_sale_collection_id', $collection->id))
                ->when($source['origin'] === 'delivery_collection', fn ($query) => $query->where('route_delivery_collection_id', $collection->id))
                ->when($source['origin'] === 'post_conversion_collection', fn ($query) => $query->where('route_post_conversion_collection_id', $collection->id))
                ->lockForUpdate()
                ->exists();
            if ($reserved) {
                throw ValidationException::withMessages(['sources' => 'Uno de los cobros ya está reservado en otra liquidación activa.']);
            }
        }

        return ['pre_sale_collection' => $pre, 'delivery_collection' => $delivery, 'post_conversion_collection' => $post];
    }

    private function reserve(RouteCashSettlement $settlement, array $sources, array $collections): void
    {
        foreach ($sources as $source) {
            $collection = $collections[$source['origin']]->get($source['collection_id']);
            RouteCashSettlementItem::query()->create([
                'route_cash_settlement_id' => $settlement->id,
                'route_pre_sale_collection_id' => $source['origin'] === 'pre_sale_collection' ? $collection->id : null,
                'route_delivery_collection_id' => $source['origin'] === 'delivery_collection' ? $collection->id : null,
                'route_post_conversion_collection_id' => $source['origin'] === 'post_conversion_collection' ? $collection->id : null,
                'amount_snapshot' => $collection->amount,
                'is_active' => true,
            ]);
        }
    }

    private function recalculateExpected(RouteCashSettlement $settlement): void
    {
        $expected = round((float) RouteCashSettlementItem::query()->where('route_cash_settlement_id', $settlement->id)->where('is_active', true)->sum('amount_snapshot'), 2);
        $received = $settlement->received_amount === null ? null : round((float) $settlement->received_amount, 2);
        $settlement->update([
            'expected_amount' => $expected,
            'difference_amount' => $received === null ? null : round($received - $expected, 2),
        ]);
    }

    private function assertActorScope(User $actor, int $businessId, int $branchId): void
    {
        if (! $actor->is_active || (int) $actor->business_id !== $businessId || (int) $actor->current_branch_id !== $branchId) {
            abort(403);
        }
    }

    private function assertCollectorScope(User $collector, int $businessId, int $branchId): void
    {
        if (! $collector->is_active || (int) $collector->business_id !== $businessId || (int) $collector->current_branch_id !== $branchId) {
            throw ValidationException::withMessages(['collector_user_id' => 'El cobrador debe estar activo y pertenecer a la sucursal actual.']);
        }
    }

    private function requireKey(string $key): void
    {
        if (blank($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'La llave de idempotencia es obligatoria.']);
        }
    }
}
