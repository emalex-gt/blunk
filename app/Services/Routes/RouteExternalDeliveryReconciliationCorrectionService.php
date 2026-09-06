<?php

namespace App\Services\Routes;

use App\Models\RouteExternalDeliveryReconciliationItem;
use App\Models\RouteExternalDeliveryReconciliationItemRevision;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteExternalDeliveryReconciliationCorrectionService
{
    private const REASONS = ['customer_absent', 'customer_rejected', 'address_issue', 'business_closed', 'damaged_goods', 'other'];
    private const FORBIDDEN = ['collected', 'amount', 'payment_method', 'collected_by', 'collected_at', 'reference', 'details', 'custody_status', 'cash_register_session_id', 'cash_movement_id', 'receive_cash_in_current_session'];

    public function correctDeliveryResult(RouteExternalDeliveryReconciliationItem $item, array $data, User $actor): RouteExternalDeliveryReconciliationItem
    {
        abort_unless(Permissions::userHas($actor, Permissions::ROUTES_EXTERNAL_DELIVERY_RECONCILE_CORRECT), 403);
        if (array_intersect(array_keys($data), self::FORBIDDEN) !== []) {
            throw ValidationException::withMessages(['correction' => 'La corrección requiere una reversión financiera o logística que aún no existe.']);
        }
        if (blank($data['correction_reason'] ?? null)) {
            throw ValidationException::withMessages(['correction_reason' => 'El motivo de la corrección es obligatorio.']);
        }

        return DB::transaction(function () use ($item, $data, $actor) {
            $locked = RouteExternalDeliveryReconciliationItem::query()->where('business_id', $actor->business_id)->whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $actor->current_branch_id === (int) $locked->branch_id && $actor->is_active, 403);
            $status = (string) ($data['delivery_status'] ?? '');
            if (! in_array($status, ['delivered', 'not_delivered'], true)) {
                throw ValidationException::withMessages(['delivery_status' => 'El estado de entrega no es válido.']);
            }
            $reason = $data['not_delivered_reason'] ?? null;
            $notes = blank($data['notes'] ?? null) ? null : trim((string) $data['notes']);
            if ($status === 'not_delivered' && ! in_array($reason, self::REASONS, true)) {
                throw ValidationException::withMessages(['not_delivered_reason' => 'Debe indicar el motivo de la no entrega.']);
            }
            if ($status === 'not_delivered' && $reason === 'other' && $notes === null) {
                throw ValidationException::withMessages(['notes' => 'Debe explicar el motivo seleccionado como otro.']);
            }
            if ($status === 'delivered') {
                $reason = null;
                $notes = null;
            }
            $previous = ['delivery_status' => $locked->delivery_status, 'not_delivered_reason' => $locked->not_delivered_reason, 'notes' => $locked->notes];
            $next = ['delivery_status' => $status, 'not_delivered_reason' => $reason, 'notes' => $notes];
            $version = (int) RouteExternalDeliveryReconciliationItemRevision::query()->where('route_external_delivery_reconciliation_item_id', $locked->id)->max('version') + 1;
            RouteExternalDeliveryReconciliationItemRevision::query()->create([
                'business_id' => $locked->business_id, 'branch_id' => $locked->branch_id,
                'route_external_delivery_reconciliation_item_id' => $locked->id, 'version' => $version,
                'previous_values' => $previous, 'new_values' => $next, 'correction_reason' => trim((string) $data['correction_reason']),
                'corrected_by' => $actor->id, 'corrected_at' => now(),
            ]);
            $locked->update($next);

            return $locked->refresh();
        });
    }
}
