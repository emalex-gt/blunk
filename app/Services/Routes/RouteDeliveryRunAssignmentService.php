<?php

namespace App\Services\Routes;

use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteDeliveryRun;
use App\Models\RouteDeliveryStop;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteDeliveryRunAssignmentService
{
    public function createDraft(User $manager, User $deliveryUser, array $entryIds): RouteDeliveryRun
    {
        abort_unless(Permissions::userHas($manager, Permissions::ROUTES_DELIVERY_RUNS_MANAGE), 403);
        if ($entryIds === []) {
            throw ValidationException::withMessages(['entries' => 'Debe asignar al menos un comprobante.']);
        }

        return DB::transaction(function () use ($manager, $deliveryUser, $entryIds) {
            $entries = $this->lockedEntries($manager, $entryIds);
            $first = $entries->first();
            $this->validateManagerScope($manager, (int) $first->batch->branch_id);
            $this->validateDeliveryUser($manager, $deliveryUser, (int) $first->batch->branch_id);
            $this->validateCompatible($entries);
            $run = RouteDeliveryRun::query()->create([
                'business_id' => $manager->business_id, 'branch_id' => $first->batch->branch_id,
                'delivery_user_id' => $deliveryUser->id, 'created_by' => $manager->id,
                'status' => 'draft', 'delivery_tracking_snapshot' => 'in_app',
                'collection_responsibility_snapshot' => $first->batch->collection_responsibility_snapshot,
            ]);
            $this->createStops($run, $entries, $manager);
            return $run->refresh();
        });
    }

    public function assignEntries(RouteDeliveryRun $run, array $entryIds, User $manager): void
    {
        abort_unless(Permissions::userHas($manager, Permissions::ROUTES_DELIVERY_RUNS_MANAGE), 403);
        DB::transaction(function () use ($run, $entryIds, $manager) {
            $run = RouteDeliveryRun::query()->where('business_id', $manager->business_id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->validateManagerScope($manager, (int) $run->branch_id);
            if ($run->status !== 'draft') throw ValidationException::withMessages(['run' => 'Sólo puede asignar comprobantes a una jornada borrador.']);
            $entries = $this->lockedEntries($manager, $entryIds);
            $this->validateCompatible($entries, $run);
            $this->createStops($run, $entries, $manager);
        });
    }

    public function assignBatch(RouteDeliveryRun $run, RouteDeliveryBatch $batch, User $manager): array
    {
        abort_unless((int) $batch->business_id === (int) $manager->business_id && (int) $batch->branch_id === (int) $run->branch_id, 403);
        $entries = RouteDeliveryBatchPreSale::query()->where('route_delivery_batch_id', $batch->id)->with(['externalDeliveryReconciliationItem', 'deliveryStop'])->get();
        $eligible = $entries->filter(fn (RouteDeliveryBatchPreSale $entry) => $entry->sale_id && ! $entry->externalDeliveryReconciliationItem && ! $entry->deliveryStop)->pluck('id')->all();
        $skipped = $entries->pluck('id')->diff($eligible)->values()->all();
        if ($eligible !== []) $this->assignEntries($run, $eligible, $manager);
        return ['assigned' => $eligible, 'skipped' => $skipped];
    }

    public function reorder(RouteDeliveryRun $run, array $stopIds, User $manager): void
    {
        abort_unless(Permissions::userHas($manager, Permissions::ROUTES_DELIVERY_RUNS_MANAGE), 403);
        DB::transaction(function () use ($run, $stopIds, $manager) {
            $lockedRun = RouteDeliveryRun::query()->where('business_id', $manager->business_id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->validateManagerScope($manager, (int) $lockedRun->branch_id);
            if ($lockedRun->status !== 'draft') throw ValidationException::withMessages(['run' => 'Sólo puede reordenar una jornada borrador.']);
            $stops = RouteDeliveryStop::query()->where('route_delivery_run_id', $lockedRun->id)->whereIn('id', $stopIds)->orderBy('id')->lockForUpdate()->get();
            if ($stops->count() !== $lockedRun->stops()->count() || $stops->count() !== count(array_unique($stopIds))) throw ValidationException::withMessages(['stops' => 'El orden debe incluir exactamente las paradas de esta jornada.']);
            foreach (array_values($stopIds) as $position => $stopId) RouteDeliveryStop::query()->whereKey($stopId)->update(['position' => $position + 1]);
        });
    }

    public function removeStop(RouteDeliveryRun $run, RouteDeliveryStop $stop, User $manager): void
    {
        abort_unless(Permissions::userHas($manager, Permissions::ROUTES_DELIVERY_RUNS_MANAGE), 403);
        DB::transaction(function () use ($run, $stop, $manager) {
            $lockedRun = RouteDeliveryRun::query()->where('business_id', $manager->business_id)->where('branch_id', $manager->current_branch_id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($lockedRun->status !== 'draft') throw ValidationException::withMessages(['run' => 'Sólo puede quitar paradas de una jornada borrador.']);
            $lockedStop = RouteDeliveryStop::query()->where('route_delivery_run_id', $lockedRun->id)->whereKey($stop->id)->lockForUpdate()->firstOrFail();
            $lockedStop->delete();
        });
    }

    private function lockedEntries(User $actor, array $ids)
    {
        if ($ids === []) {
            throw ValidationException::withMessages(['entries' => 'No hay comprobantes elegibles para asignar.']);
        }
        $entries = RouteDeliveryBatchPreSale::query()->whereIn('id', $ids)->with(['batch', 'sale.customer', 'externalDeliveryReconciliationItem'])->orderBy('id')->lockForUpdate()->get();
        if ($entries->count() !== count(array_unique($ids))) throw ValidationException::withMessages(['entries' => 'Hay comprobantes inválidos para asignar.']);
        foreach ($entries as $entry) {
            if (! $entry->sale_id || (int) $entry->batch->business_id !== (int) $actor->business_id || $entry->batch->delivery_tracking_snapshot !== 'in_app' || $entry->externalDeliveryReconciliationItem || RouteDeliveryStop::query()->where('route_delivery_batch_pre_sale_id', $entry->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['entries' => 'El comprobante no es elegible para entrega dentro de Blunk.']);
            }
        }
        return $entries;
    }

    private function validateDeliveryUser(User $manager, User $deliveryUser, int $branchId): void
    {
        abort_unless($deliveryUser->is_active && (int) $deliveryUser->business_id === (int) $manager->business_id && (int) $deliveryUser->current_branch_id === $branchId, 403);
        abort_unless((int) BranchInventory::activeBranch((int) $manager->business_id)->id === $branchId, 403);
    }

    private function validateManagerScope(User $manager, int $branchId): void
    {
        abort_unless($manager->is_active && (int) $manager->current_branch_id === $branchId, 403);
        abort_unless((int) BranchInventory::activeBranch((int) $manager->business_id)->id === $branchId, 403);
    }

    private function validateCompatible($entries, ?RouteDeliveryRun $run = null): void
    {
        $first = $entries->first();
        foreach ($entries as $entry) {
            if ((int) $entry->batch->branch_id !== (int) $first->batch->branch_id || $entry->batch->collection_responsibility_snapshot !== $first->batch->collection_responsibility_snapshot || ($run && ((int) $entry->batch->branch_id !== (int) $run->branch_id || $entry->batch->collection_responsibility_snapshot !== $run->collection_responsibility_snapshot))) {
                throw ValidationException::withMessages(['entries' => 'Los comprobantes deben compartir sucursal y responsabilidad de cobro.']);
            }
        }
    }

    private function createStops(RouteDeliveryRun $run, $entries, User $manager): void
    {
        $position = (int) $run->stops()->max('position');
        foreach ($entries as $entry) {
            $sale = $entry->sale; $customer = $sale?->customer;
            RouteDeliveryStop::query()->create(['business_id' => $run->business_id, 'branch_id' => $run->branch_id, 'route_delivery_run_id' => $run->id, 'route_delivery_batch_id' => $entry->route_delivery_batch_id, 'route_delivery_batch_pre_sale_id' => $entry->id, 'pre_sale_id' => $entry->pre_sale_id, 'sale_id' => $entry->sale_id, 'customer_id' => $sale?->customer_id, 'customer_name_snapshot' => $sale?->customer_name ?? $customer?->name, 'customer_address_snapshot' => $sale?->customer_address ?? $customer?->address, 'customer_phone_snapshot' => $sale?->customer_phone ?? $customer?->phone, 'position' => ++$position, 'delivery_tracking_snapshot' => 'in_app', 'collection_responsibility_snapshot' => $run->collection_responsibility_snapshot, 'status' => 'pending', 'assigned_by' => $manager->id, 'assigned_at' => now()]);
        }
    }
}
