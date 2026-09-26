<?php

namespace App\Services\Routes;

use App\Models\RouteDeliveryRun;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteDeliveryRunService
{
    public function __construct(private readonly RouteCashOperationGuard $cash) {}

    public function start(RouteDeliveryRun $run, User $actor, string $key): IdempotencyResult
    {
        return $this->transition($run, $actor, $key, 'start', function (RouteDeliveryRun $locked) use ($actor) {
            if ($locked->status !== 'draft') throw ValidationException::withMessages(['run' => 'La jornada no está lista para iniciar.']);
            if (! $locked->stops()->exists()) throw ValidationException::withMessages(['run' => 'La jornada no tiene comprobantes asignados.']);
            $locked->update(['status' => 'open', 'started_by' => $actor->id, 'started_at' => now()]);
        });
    }

    public function close(RouteDeliveryRun $run, User $actor, string $key, bool $confirmUnpaid): IdempotencyResult
    {
        return $this->transition($run, $actor, $key, 'close', function (RouteDeliveryRun $locked) use ($actor, $confirmUnpaid) {
            $progress = $this->progress($locked);
            if ($locked->status !== 'open') throw ValidationException::withMessages(['run' => 'La jornada no está abierta.']);
            if ($progress['pending'] > 0) throw ValidationException::withMessages(['run' => 'Debe resolver todas las paradas pendientes antes de cerrar.']);
            if ($progress['unpaid_delivered_count'] > 0 && ! $confirmUnpaid) throw ValidationException::withMessages(['confirm_unpaid' => "Esta jornada tiene {$progress['unpaid_delivered_count']} ventas entregadas pendientes de cobro por Q ".number_format($progress['unpaid_delivered_amount'], 2).'.']);
            $locked->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()]);
        });
    }

    public function progress(RouteDeliveryRun $run): array
    {
        $rows = $run->stops()->join('sales', 'sales.id', '=', 'route_delivery_stops.sale_id')->selectRaw("COUNT(*) FILTER (WHERE route_delivery_stops.status = 'pending') AS pending, COUNT(*) FILTER (WHERE route_delivery_stops.status = 'delivered') AS delivered, COUNT(*) FILTER (WHERE route_delivery_stops.status = 'not_delivered') AS not_delivered, COALESCE(SUM(sales.total) FILTER (WHERE route_delivery_stops.status = 'delivered' AND sales.status = 'completed' AND sales.payment_status = 'unpaid'), 0) AS unpaid_delivered_amount, COUNT(*) FILTER (WHERE route_delivery_stops.status = 'delivered' AND sales.status = 'completed' AND sales.payment_status = 'unpaid') AS unpaid_delivered_count")->first();
        return ['assigned' => $run->stops()->count(), 'pending' => (int) $rows->pending, 'delivered' => (int) $rows->delivered, 'not_delivered' => (int) $rows->not_delivered, 'unpaid_delivered_count' => (int) $rows->unpaid_delivered_count, 'unpaid_delivered_amount' => (float) $rows->unpaid_delivered_amount];
    }

    private function transition(RouteDeliveryRun $run, User $actor, string $key, string $action, callable $operation): IdempotencyResult
    {
        $businessId = (int) $run->business_id; $branchId = (int) $run->branch_id;
        abort_unless((int) $actor->business_id === $businessId && (int) $actor->current_branch_id === $branchId && $actor->is_active && (int) $run->delivery_user_id === (int) $actor->id, 403);
        abort_unless(Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_EXECUTE), 403);
        return app(IdempotencyService::class)->run($businessId, $branchId, $actor->id, "route_delivery_run_{$action}", $key, ['run_id' => $run->id], function () use ($run, $actor, $operation, $businessId, $branchId) {
            return DB::transaction(function () use ($run, $actor, $operation, $businessId, $branchId) {
                abort_unless((int) BranchInventory::activeBranch($businessId)->id === $branchId, 403);
                $locked = RouteDeliveryRun::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereKey($run->id)->lockForUpdate()->firstOrFail();
                $this->cash->requireOpen($businessId, $branchId, true);
                $operation($locked);
                return ['result_id' => $locked->id, 'response_payload' => $this->progress($locked)];
            });
        }, 'route_delivery_run');
    }
}
