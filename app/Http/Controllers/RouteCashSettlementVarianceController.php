<?php

namespace App\Http\Controllers;

use App\Models\RouteCashSettlementVariance;
use App\Models\User;
use App\Services\Routes\RouteCashSettlementVarianceService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RouteCashSettlementVarianceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->guard($request, Permissions::ROUTES_CASH_VARIANCES_VIEW);
        $actor = $request->user();
        $scope = RouteCashSettlementVariance::query()
            ->where('business_id', currentBusinessId())
            ->where('branch_id', $actor->current_branch_id);
        $summary = (clone $scope)
            ->where('status', 'open')
            ->selectRaw("COALESCE(SUM(CASE WHEN difference_amount < 0 THEN 1 ELSE 0 END), 0) AS open_shortages_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN difference_amount < 0 THEN ABS(difference_amount) ELSE 0 END), 0) AS open_shortages_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN difference_amount > 0 THEN 1 ELSE 0 END), 0) AS open_overages_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN difference_amount > 0 THEN ABS(difference_amount) ELSE 0 END), 0) AS open_overages_amount")
            ->first();
        $variances = $scope
            ->with(['settlement.collector:id,name', 'assignee:id,name'])
            ->withSum('resolutions as resolved_amount', 'amount')
            ->latest('id')
            ->paginate(30)
            ->through(fn (RouteCashSettlementVariance $variance) => $this->payload($variance));

        return Inertia::render('Routes/CashSettlements/Variances/Index', [
            'summary' => [
                'open_shortages' => ['count' => (int) $summary->open_shortages_count, 'amount' => (float) $summary->open_shortages_amount],
                'open_overages' => ['count' => (int) $summary->open_overages_count, 'amount' => (float) $summary->open_overages_amount],
            ],
            'variances' => $variances,
        ]);
    }

    public function show(Request $request, RouteCashSettlementVariance $variance): Response
    {
        $this->scope($request, $variance);
        $variance->load([
            'settlement.collector:id,name',
            'settlement.cashMovement',
            'settlement.items.preSaleCollection.preSale.customer:id,name',
            'settlement.items.deliveryCollection.sale.customer:id,name',
            'events.recorder:id,name',
            'resolutions.cashMovement',
            'resolutions.counterparty:id,name',
            'resolutions.recorder:id,name',
            'assignee:id,name',
        ])->loadSum('resolutions as resolved_amount', 'amount');
        $actor = $request->user();

        return Inertia::render('Routes/CashSettlements/Variances/Show', [
            'variance' => $this->payload($variance, true),
            'can_manage' => Permissions::userHas($actor, Permissions::ROUTES_CASH_VARIANCES_MANAGE),
            'can_resolve' => Permissions::userHas($actor, Permissions::ROUTES_CASH_VARIANCES_RESOLVE),
            'assignees' => User::query()->where('business_id', currentBusinessId())->where('current_branch_id', $actor->current_branch_id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function event(Request $request, RouteCashSettlementVariance $variance, RouteCashSettlementVarianceService $service)
    {
        $this->scope($request, $variance);
        $this->guard($request, Permissions::ROUTES_CASH_VARIANCES_MANAGE);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'type' => ['required', 'in:note,investigation,collector_contact'],
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $service->addEvent($variance, $data, $request->user());

        return back()->with('success', 'Seguimiento registrado.');
    }

    public function assignment(Request $request, RouteCashSettlementVariance $variance, RouteCashSettlementVarianceService $service)
    {
        $this->scope($request, $variance);
        $this->guard($request, Permissions::ROUTES_CASH_VARIANCES_MANAGE);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'assigned_to' => ['nullable', 'integer'],
        ]);
        $service->assign($variance, $data['assigned_to'] ?? null, $request->user(), $data['idempotency_key']);

        return back()->with('success', 'Responsable actualizado.');
    }

    public function resolve(Request $request, RouteCashSettlementVariance $variance, RouteCashSettlementVarianceService $service)
    {
        $this->scope($request, $variance);
        $this->guard($request, Permissions::ROUTES_CASH_VARIANCES_RESOLVE);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'type' => ['required', 'in:shortage_cash_received,overage_cash_returned'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'counterparty_user_id' => ['required', 'integer'],
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $service->resolve($variance, $data, $request->user());

        return back()->with('success', 'Resolución física registrada.');
    }

    private function guard(Request $request, string $permission): void
    {
        $actor = $request->user();
        abort_unless($actor && $actor->is_active && (int) $actor->business_id === currentBusinessId() && Permissions::userHas($actor, $permission), 403);
        BranchInventory::activeBranch(currentBusinessId());
    }

    private function scope(Request $request, RouteCashSettlementVariance $variance): void
    {
        $actor = $request->user();
        abort_unless($actor && $actor->is_active && (int) $actor->business_id === currentBusinessId() && (int) $variance->business_id === currentBusinessId() && (int) $variance->branch_id === (int) $actor->current_branch_id, 403);
        BranchInventory::activeBranch(currentBusinessId());
    }

    private function payload(RouteCashSettlementVariance $variance, bool $detail = false): array
    {
        $resolved = $variance->resolved_amount ?? $variance->resolutions?->sum('amount') ?? 0;
        $remaining = round(abs((float) $variance->difference_amount) - (float) $resolved, 2);
        $settlement = $variance->settlement;
        $payload = [
            'id' => $variance->id,
            'status' => $variance->status,
            'difference_amount' => (float) $variance->difference_amount,
            'remaining_amount' => $remaining,
            'derived_type' => (float) $variance->difference_amount < 0 ? 'shortage' : 'overage',
            'reason_code' => $variance->reason_code,
            'explanation' => $variance->explanation,
            'opened_at' => $variance->opened_at?->toIso8601String(),
            'aging_days' => $variance->opened_at?->startOfDay()->diffInDays(now()->startOfDay()) ?? 0,
            'settlement_id' => $variance->route_cash_settlement_id,
            'collector' => $settlement?->collector ? ['id' => $settlement->collector->id, 'name' => $settlement->collector->name] : null,
            'collector_user_id' => $variance->collector_user_id,
            'branch_id' => $variance->branch_id,
            'assigned_to' => $variance->assignee ? ['id' => $variance->assignee->id, 'name' => $variance->assignee->name] : null,
            'expected_amount' => $settlement?->expected_amount === null ? null : (float) $settlement->expected_amount,
            'received_amount' => $settlement?->received_amount === null ? null : (float) $settlement->received_amount,
        ];
        if (! $detail) {
            return $payload;
        }

        return [
            ...$payload,
            'original_movement' => $settlement?->cashMovement ? [
                'id' => $settlement->cashMovement->id,
                'amount' => (float) $settlement->cashMovement->amount,
                'type' => $settlement->cashMovement->type,
            ] : null,
            'items' => $settlement?->items->map(function ($item) {
                $customer = $item->preSaleCollection?->preSale?->customer ?? $item->deliveryCollection?->sale?->customer;
                return ['id' => $item->id, 'amount' => (float) $item->amount_snapshot, 'customer_name' => $customer?->name];
            })->values() ?? [],
            'events' => $variance->events->map(fn ($event) => [
                'id' => $event->id, 'type' => $event->type, 'note' => $event->note, 'occurred_at' => $event->occurred_at?->toIso8601String(),
                'recorded_by' => $event->recorder ? ['id' => $event->recorder->id, 'name' => $event->recorder->name] : null,
            ])->values(),
            'resolutions' => $variance->resolutions->map(fn ($resolution) => [
                'id' => $resolution->id, 'type' => $resolution->type, 'amount' => (float) $resolution->amount,
                'occurred_at' => $resolution->occurred_at?->toIso8601String(), 'note' => $resolution->note,
                'movement' => $resolution->cashMovement ? ['id' => $resolution->cashMovement->id, 'amount' => (float) $resolution->cashMovement->amount] : null,
                'counterparty' => $resolution->counterparty ? ['id' => $resolution->counterparty->id, 'name' => $resolution->counterparty->name] : null,
            ])->values(),
        ];
    }
}
