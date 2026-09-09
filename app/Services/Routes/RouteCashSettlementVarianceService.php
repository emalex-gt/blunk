<?php

namespace App\Services\Routes;

use App\Models\OperationIdempotencyKey;
use App\Models\RouteCashSettlementVariance;
use App\Models\RouteCashSettlementVarianceEvent;
use App\Models\RouteCashSettlementVarianceResolution;
use App\Models\User;
use App\Support\CashRegister;
use App\Support\IdempotencyResult;
use App\Support\IdempotencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RouteCashSettlementVarianceService
{
    private const EVENT_TYPES = ['note', 'investigation', 'collector_contact'];

    public function remainingAmount(RouteCashSettlementVariance $variance): float
    {
        return round(abs((float) $variance->difference_amount) - (float) RouteCashSettlementVarianceResolution::query()->where('variance_id', $variance->id)->sum('amount'), 2);
    }

    public function addEvent(RouteCashSettlementVariance $variance, array $data, User $actor): IdempotencyResult
    {
        $key = (string) ($data['idempotency_key'] ?? '');
        if (blank($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'La llave de idempotencia es obligatoria.']);
        }

        return app(IdempotencyService::class)->run((int) $variance->business_id, (int) $variance->branch_id, $actor->id, 'route_cash_settlement_variance_event', $key, ['variance_id' => $variance->id, ...$data], function () use ($variance, $data, $actor, $key): array {
            return DB::transaction(function () use ($variance, $data, $actor, $key): array {
                $locked = RouteCashSettlementVariance::query()->lockForUpdate()->findOrFail($variance->id);
                $this->assertScope($locked, $actor);
                if ($locked->status !== 'open') {
                    throw ValidationException::withMessages(['variance' => 'Sólo se puede registrar seguimiento en una diferencia abierta.']);
                }
                if (! in_array($data['type'] ?? null, self::EVENT_TYPES, true) || blank($data['note'] ?? null)) {
                    throw ValidationException::withMessages(['event' => 'El evento requiere tipo y nota válidos.']);
                }
                $event = RouteCashSettlementVarianceEvent::query()->create([
                    'variance_id' => $locked->id, 'business_id' => $locked->business_id, 'branch_id' => $locked->branch_id,
                    'type' => $data['type'], 'note' => trim((string) $data['note']), 'occurred_at' => $data['occurred_at'] ?? now(), 'recorded_by' => $actor->id,
                    'operation_idempotency_key_id' => $this->idempotencyId($locked, $actor, 'route_cash_settlement_variance_event', $key),
                ]);
                return ['result_id' => $event->id, 'response_payload' => ['event_id' => $event->id]];
            });
        }, 'route_cash_settlement_variance_event');
    }

    public function assign(RouteCashSettlementVariance $variance, ?int $assignedTo, User $actor, string $key): IdempotencyResult
    {
        if (blank($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'La llave de idempotencia es obligatoria.']);
        }

        return app(IdempotencyService::class)->run((int) $variance->business_id, (int) $variance->branch_id, $actor->id, 'route_cash_settlement_variance_assign', $key, ['variance_id' => $variance->id, 'assigned_to' => $assignedTo], function () use ($variance, $assignedTo, $actor): array {
            return DB::transaction(function () use ($variance, $assignedTo, $actor): array {
                $locked = RouteCashSettlementVariance::query()->lockForUpdate()->findOrFail($variance->id);
                $this->assertScope($locked, $actor);
                if ($assignedTo !== null) {
                    $assignee = User::query()->whereKey($assignedTo)->lockForUpdate()->firstOrFail();
                    if (! $assignee->is_active || (int) $assignee->business_id !== (int) $locked->business_id || (int) $assignee->current_branch_id !== (int) $locked->branch_id) {
                        throw ValidationException::withMessages(['assigned_to' => 'El responsable debe estar activo en el negocio y sucursal de la diferencia.']);
                    }
                }
                $locked->update(['assigned_to' => $assignedTo]);
                return ['result_id' => $locked->id, 'response_payload' => ['variance_id' => $locked->id]];
            });
        }, 'route_cash_settlement_variance');
    }

    public function resolve(RouteCashSettlementVariance $variance, array $data, User $actor): IdempotencyResult
    {
        $key = (string) ($data['idempotency_key'] ?? '');
        if (blank($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'La llave de idempotencia es obligatoria.']);
        }

        return app(IdempotencyService::class)->run((int) $variance->business_id, (int) $variance->branch_id, $actor->id, 'route_cash_settlement_variance_resolution', $key, ['variance_id' => $variance->id, ...$data], function () use ($variance, $data, $actor, $key): array {
            return DB::transaction(function () use ($variance, $data, $actor, $key): array {
                $locked = RouteCashSettlementVariance::query()->lockForUpdate()->findOrFail($variance->id);
                $this->assertScope($locked, $actor);
                $existing = RouteCashSettlementVarianceResolution::query()->where('variance_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
                $remaining = round(abs((float) $locked->difference_amount) - (float) $existing->sum('amount'), 2);
                $amount = round((float) ($data['amount'] ?? 0), 2);
                $type = (string) ($data['type'] ?? '');
                $validType = $locked->difference_amount < 0 ? $type === 'shortage_cash_received' : $type === 'overage_cash_returned';
                if ($locked->status !== 'open' || $amount <= 0 || $amount > $remaining || ! $validType || blank($data['note'] ?? null) || (int) ($data['counterparty_user_id'] ?? 0) !== (int) $locked->collector_user_id) {
                    throw ValidationException::withMessages(['resolution' => 'La resolución no es válida para esta diferencia abierta.']);
                }
                $session = CashRegister::requireOpenSession((int) $locked->business_id, 'Debe existir una caja abierta actual para registrar la resolución física.', true, (int) $locked->branch_id);
                $resolution = RouteCashSettlementVarianceResolution::query()->create([
                    'variance_id' => $locked->id, 'business_id' => $locked->business_id, 'branch_id' => $locked->branch_id, 'type' => $type, 'amount' => $amount,
                    'cash_register_session_id' => $session->id, 'counterparty_user_id' => $locked->collector_user_id, 'recorded_by' => $actor->id,
                    'occurred_at' => $data['occurred_at'] ?? now(), 'note' => trim((string) $data['note']),
                    'operation_idempotency_key_id' => $this->idempotencyId($locked, $actor, 'route_cash_settlement_variance_resolution', $key),
                ]);
                $movement = CashRegister::recordMovement($session, $type === 'shortage_cash_received' ? 'route_cash_variance_shortage_received' : 'route_cash_variance_overage_returned', $type === 'shortage_cash_received' ? $amount : -$amount, 'route_cash_settlement_variance_resolution', $resolution->id, "Resolución física de diferencia de liquidación #{$locked->route_cash_settlement_id}", $actor->id);
                $resolution->update(['cash_movement_id' => $movement->id]);
                if (round($remaining - $amount, 2) === 0.0) {
                    $locked->update(['status' => 'resolved', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_note' => trim((string) $data['note'])]);
                }
                return ['result_id' => $resolution->id, 'response_payload' => ['resolution_id' => $resolution->id]];
            });
        }, 'route_cash_settlement_variance_resolution');
    }

    private function idempotencyId(RouteCashSettlementVariance $variance, User $actor, string $operation, string $key): ?int
    {
        return OperationIdempotencyKey::query()->where('business_id', $variance->business_id)->where('branch_id', $variance->branch_id)->where('user_id', $actor->id)->where('operation_type', $operation)->where('idempotency_key', $key)->value('id');
    }

    private function assertScope(RouteCashSettlementVariance $variance, User $actor): void
    {
        if (! $actor->is_active || (int) $actor->business_id !== (int) $variance->business_id || (int) $actor->current_branch_id !== (int) $variance->branch_id) {
            abort(403);
        }
    }
}
