<?php

namespace App\Services\Routes;

use App\Models\RouteDeliveryStop;
use App\Models\RouteDeliveryStopRevision;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteDeliveryStopCorrectionService
{
    private const FORBIDDEN = [
        'collected', 'amount', 'payment_method', 'collected_by', 'collected_at',
        'reference', 'details', 'override_reason', 'receive_cash_in_current_session',
        'cash_movement_id', 'cash_register_session_id', 'cash_posting_state',
        'custody_status', 'cash_custody_policy_snapshot', 'sale_id', 'pre_sale_id',
        'route_delivery_batch_pre_sale_id', 'route_delivery_run_id', 'delivery_origin',
        'fel_status', 'certification_status', 'stock', 'reservation', 'reservations',
        'refund', 'refund_amount', 'sale', 'cash', 'payment', 'collection', 'fel',
    ];

    public function correct(RouteDeliveryStop $stop, array $data, User $actor): RouteDeliveryStop
    {
        abort_unless(Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_CORRECT), 403);
        if (array_intersect(array_keys($data), self::FORBIDDEN) !== []) throw ValidationException::withMessages(['correction' => 'La corrección financiera o logística requiere una fase administrativa futura.']);
        if (blank($data['correction_reason'] ?? null)) throw ValidationException::withMessages(['correction_reason' => 'El motivo de corrección es obligatorio.']);
        return DB::transaction(function () use ($stop, $data, $actor) {
            $locked = RouteDeliveryStop::query()->where('business_id', $actor->business_id)->whereKey($stop->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && (int) $actor->current_branch_id === (int) $locked->branch_id, 403);
            $outcome = DeliveryOutcomeRules::normalize((string) ($data['delivery_status'] ?? ''), $data['not_delivered_reason_code'] ?? $data['not_delivered_reason'] ?? null, $data['delivery_notes'] ?? $data['notes'] ?? null);
            $previous = ['delivery_status' => $locked->status, 'not_delivered_reason_code' => $locked->not_delivered_reason_code, 'delivery_notes' => $locked->delivery_notes];
            $next = ['status' => $outcome['delivery_status'], 'not_delivered_reason_code' => $outcome['not_delivered_reason_code'], 'delivery_notes' => $outcome['delivery_notes']];
            $version = (int) RouteDeliveryStopRevision::query()->where('route_delivery_stop_id', $locked->id)->max('version') + 1;
            RouteDeliveryStopRevision::query()->create(['business_id' => $locked->business_id, 'branch_id' => $locked->branch_id, 'route_delivery_stop_id' => $locked->id, 'version' => $version, 'previous_values' => $previous, 'new_values' => $next, 'correction_reason' => trim($data['correction_reason']), 'corrected_by' => $actor->id, 'corrected_at' => now()]);
            $locked->update($next);
            return $locked->refresh();
        });
    }
}
