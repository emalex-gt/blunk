<?php

namespace App\Services\Routes;

use App\Models\RouteDeliveryCollection;
use App\Models\RouteExternalDeliveryReconciliationItem;
use App\Models\RouteDeliveryStop;
use App\Models\Sale;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\CashRegister;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteDeliveryCollectionService
{
    private const METHODS = ['cash', 'card', 'transfer', 'check'];

    public function captureFull(RouteExternalDeliveryReconciliationItem|RouteDeliveryStop $item, array $data, User $actor): RouteDeliveryCollection
    {
        return DB::transaction(function () use ($item, $data, $actor) {
            $external = $item instanceof RouteExternalDeliveryReconciliationItem;
            $lockedItem = $external
                ? RouteExternalDeliveryReconciliationItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail()
                : RouteDeliveryStop::query()->whereKey($item->id)->with('run')->lockForUpdate()->firstOrFail();
            $businessId = (int) $lockedItem->business_id;
            $branchId = (int) $lockedItem->branch_id;
            abort_unless((int) $actor->business_id === $businessId && (int) $actor->current_branch_id === $branchId && $actor->is_active, 403);
            abort_unless((int) BranchInventory::activeBranch($businessId)->id === $branchId, 403);
            if ($lockedItem->collection_responsibility_snapshot !== 'delivery_agent') {
                throw ValidationException::withMessages(['collection' => 'Sólo el entregador puede registrar este cobro posterior a la venta.']);
            }
            if (! $external) {
                abort_unless($lockedItem->run && $lockedItem->run->status === 'open' && (int) $lockedItem->run->delivery_user_id === (int) $actor->id, 403);
                abort_unless(Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_EXECUTE), 403);
            }

            $sale = Sale::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($lockedItem->sale_id)->lockForUpdate()->firstOrFail();
            if ($sale->payment_status !== 'unpaid' || (float) $sale->amount_paid !== 0.0 || $sale->payments()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['sale' => 'La venta ya tiene un pago registrado.']);
            }
            if (RouteDeliveryCollection::query()->where('business_id', $businessId)->where('branch_id', $branchId)->where('sale_id', $sale->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['collection' => 'La venta ya tiene un cobro de entrega.']);
            }

            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($amount !== round((float) $sale->total, 2)) {
                throw ValidationException::withMessages(['amount' => 'El cobro debe coincidir exactamente con el total de la venta.']);
            }
            $method = (string) ($data['payment_method'] ?? '');
            if (! in_array($method, self::METHODS, true)) {
                throw ValidationException::withMessages(['payment_method' => 'La forma de pago no es válida.']);
            }
            // In-app execution normally records the authenticated deliverer as
            // collector. An explicit different user remains an audited override.
            $collectorId = (int) ($data['collected_by'] ?? $actor->id);
            $collector = User::query()->where('business_id', $businessId)->where('is_active', true)->whereKey($collectorId)->first();
            if (! $collector || (int) $collector->current_branch_id !== $branchId) {
                throw ValidationException::withMessages(['collected_by' => 'El cobrador debe ser un usuario activo de la sucursal.']);
            }
            $isOverride = (int) $collector->id !== (int) $actor->id;
            if ($isOverride && ! (Permissions::userHas($actor, Permissions::ROUTES_EXTERNAL_DELIVERY_COLLECTION_OVERRIDE) || Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_COLLECTIONS_OVERRIDE))) {
                abort(403);
            }
            if ($isOverride && blank($data['override_reason'] ?? null)) {
                throw ValidationException::withMessages(['override_reason' => 'El motivo del override es obligatorio.']);
            }
            if (blank($data['collected_at'] ?? null)) {
                throw ValidationException::withMessages(['collected_at' => 'La fecha y hora del cobro son obligatorias.']);
            }
            $collectedAt = $data['collected_at'];

            $policy = TenantSetting::query()->where('business_id', $businessId)->value('route_cash_custody_policy') ?? 'collector_custody_until_settlement';
            $receivingCashNow = $method === 'cash' && (bool) ($data['receive_cash_in_current_session'] ?? false);
            $session = null;
            $postCash = $method === 'cash' && $policy === 'immediate_branch_register' && $receivingCashNow;
            if ($postCash) {
                $session = CashRegister::requireOpenSession($businessId, 'Debe existir una caja abierta para recibir efectivo ahora.', true, $branchId);
            }
            $collection = RouteDeliveryCollection::query()->create([
                'business_id' => $businessId, 'branch_id' => $branchId, 'sale_id' => $sale->id, 'pre_sale_id' => $lockedItem->pre_sale_id,
                'route_external_delivery_reconciliation_item_id' => $external ? $lockedItem->id : null, 'route_delivery_stop_id' => $external ? null : $lockedItem->id, 'delivery_origin' => $external ? 'external_reconciliation' : 'in_app_stop', 'collected_by' => $collector->id, 'recorded_by' => $actor->id,
                'amount' => $amount, 'payment_method' => $method, 'reference' => $data['reference'] ?? null, 'details' => $data['details'] ?? null,
                'collected_at' => $collectedAt, 'cash_custody_policy_snapshot' => $policy,
                'custody_status' => $method !== 'cash' ? 'not_applicable' : ($postCash ? 'posted_to_branch_cash' : 'held_by_collector'),
                'cash_posting_state' => $method !== 'cash' ? 'not_applicable' : ($postCash ? 'posted_to_current_session' : 'awaiting_physical_receipt'),
                'physical_branch_receipt_confirmed_at' => $postCash ? now() : null,
                'physical_branch_receipt_confirmed_by' => $postCash ? $actor->id : null,
                'cash_register_session_id' => $postCash ? $session->id : null,
                'override_reason' => $isOverride ? trim((string) $data['override_reason']) : null,
            ]);
            if ($postCash) {
                $movement = CashRegister::recordMovement($session, 'sale_cash', $amount, 'route_delivery_collection', $collection->id, "Cobro de entrega #{$lockedItem->id}", $actor->id);
                $collection->update(['cash_movement_id' => $movement->id]);
            }
            $sale->payments()->create([
                'business_id' => $businessId, 'method' => $method, 'amount' => $amount, 'reference' => $data['reference'] ?? null,
                'details' => $data['details'] ?? null, 'collected_by' => $collector->id, 'collected_at' => $collectedAt,
                'cash_register_session_id' => $postCash ? $session->id : null, 'route_delivery_collection_id' => $collection->id,
            ]);
            $sale->update(['payment_status' => 'paid', 'amount_paid' => $amount, 'payment_method' => $method]);

            return $collection->refresh();
        });
    }
}
