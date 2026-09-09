<?php

namespace App\Http\Controllers;

use App\Models\RouteCashSettlement;
use App\Models\RouteCashSettlementItem;
use App\Models\User;
use App\Services\Routes\RouteCashSettlementDraftService;
use App\Services\Routes\RouteCashSettlementEligibility;
use App\Services\Routes\RouteCashSettlementService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RouteCashSettlementController extends Controller
{
    public function index(Request $request, RouteCashSettlementEligibility $eligibility): Response
    {
        $this->requireAnyPermission($request, [
            Permissions::ROUTES_CASH_SETTLEMENTS_VIEW,
            Permissions::ROUTES_CASH_SETTLEMENTS_REVIEW,
        ]);

        $actor = $request->user();
        $collectorId = $request->integer('collector_id') ?: null;
        $collectors = User::query()
            ->where('business_id', currentBusinessId())
            ->where('current_branch_id', $actor->current_branch_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values();

        if ($collectorId) {
            $this->collector($collectorId, $actor);
        }

        $settlements = RouteCashSettlement::query()
            ->where('business_id', currentBusinessId())
            ->where('branch_id', $actor->current_branch_id)
            ->with(['collector:id,name', 'receivedBy:id,name', 'cashSession:id,branch_id,status'])
            ->latest('id')
            ->paginate(30)
            ->through(fn (RouteCashSettlement $settlement) => $this->summary($settlement));

        return Inertia::render('Routes/CashSettlements/Index', [
            'settlements' => $settlements,
            'collectors' => $collectors,
            'selected_collector_id' => $collectorId,
            'eligible_collections' => $collectorId
                ? $this->eligiblePayload($eligibility->forCollector(currentBusinessId(), (int) $actor->current_branch_id, $collectorId))
                : [],
            'can_create' => Permissions::userHas($actor, Permissions::ROUTES_CASH_SETTLEMENTS_CREATE),
            'can_confirm' => Permissions::userHas($actor, Permissions::ROUTES_CASH_SETTLEMENTS_CONFIRM),
            'can_confirm_variance' => Permissions::userHas($actor, Permissions::ROUTES_CASH_SETTLEMENTS_CONFIRM_VARIANCE),
            'can_view_variances' => Permissions::userHas($actor, Permissions::ROUTES_CASH_VARIANCES_VIEW),
            'can_review' => Permissions::userHas($actor, Permissions::ROUTES_CASH_SETTLEMENTS_REVIEW),
        ]);
    }

    public function show(Request $request, RouteCashSettlement $settlement, RouteCashSettlementEligibility $eligibility): Response
    {
        $this->authorizeSettlement($request, $settlement, [
            Permissions::ROUTES_CASH_SETTLEMENTS_VIEW,
            Permissions::ROUTES_CASH_SETTLEMENTS_REVIEW,
        ]);

        $actor = $request->user();
        $settlement->load([
            'collector:id,name',
            'items' => fn ($query) => $query->with([
                'preSaleCollection.preSale.customer:id,name',
                'deliveryCollection.sale.customer:id,name',
            ])->orderBy('id'),
        ]);
        $settlementUserNames = User::query()
            ->where('business_id', currentBusinessId())
            ->whereIn('id', array_filter([
                $settlement->received_by,
                $settlement->recorded_by,
                $settlement->confirmed_by,
                $settlement->cancelled_by,
            ]))
            ->pluck('name', 'id');

        return Inertia::render('Routes/CashSettlements/Show', [
            'settlement' => [
                ...$this->summary($settlement),
                'received_by' => $this->userPayload($settlement->received_by, $settlementUserNames),
                'recorded_by' => $this->userPayload($settlement->recorded_by, $settlementUserNames),
                'confirmed_by' => $this->userPayload($settlement->confirmed_by, $settlementUserNames),
                'cancelled_by' => $this->userPayload($settlement->cancelled_by, $settlementUserNames),
                'received_amount' => $settlement->received_amount === null ? null : (float) $settlement->received_amount,
                'difference_amount' => $settlement->difference_amount === null ? null : (float) $settlement->difference_amount,
                'notes' => $settlement->notes,
                'cancellation_reason' => $settlement->cancellation_reason,
                'confirmed_at' => $settlement->confirmed_at?->toIso8601String(),
                'cancelled_at' => $settlement->cancelled_at?->toIso8601String(),
                'items' => $settlement->items->map(fn (RouteCashSettlementItem $item) => $this->itemPayload($item))->values(),
            ],
            'eligible_collections' => $settlement->status === 'draft'
                ? $this->eligiblePayload($eligibility->forCollector(currentBusinessId(), (int) $actor->current_branch_id, (int) $settlement->collector_user_id))
                : [],
            'receivers' => User::query()
                ->where('business_id', currentBusinessId())
                ->where('current_branch_id', $actor->current_branch_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->values(),
            'can_create' => Permissions::userHas($actor, Permissions::ROUTES_CASH_SETTLEMENTS_CREATE),
            'can_confirm' => Permissions::userHas($actor, Permissions::ROUTES_CASH_SETTLEMENTS_CONFIRM),
            'can_confirm_variance' => Permissions::userHas($actor, Permissions::ROUTES_CASH_SETTLEMENTS_CONFIRM_VARIANCE),
            'can_review' => Permissions::userHas($actor, Permissions::ROUTES_CASH_SETTLEMENTS_REVIEW),
        ]);
    }

    public function store(Request $request, RouteCashSettlementDraftService $drafts): RedirectResponse
    {
        $this->requirePermission($request, Permissions::ROUTES_CASH_SETTLEMENTS_CREATE);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'collector_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'items' => ['required', 'array', 'min:1'],
            'items.*.origin' => ['required', Rule::in(['pre_sale_collection', 'delivery_collection'])],
            'items.*.collection_id' => ['required', 'integer', 'distinct'],
        ]);
        $this->collector((int) $data['collector_user_id'], $request->user());
        $result = $drafts->create($request->user(), (int) $data['collector_user_id'], $data['items'], $data['idempotency_key']);
        $settlement = RouteCashSettlement::query()->findOrFail($result->resultId);

        return redirect()->route('routes.cash-settlements.show', $settlement)->with('success', 'Borrador de liquidación creado.');
    }

    public function addItems(Request $request, RouteCashSettlement $settlement, RouteCashSettlementDraftService $drafts): RedirectResponse
    {
        $this->authorizeSettlement($request, $settlement, [Permissions::ROUTES_CASH_SETTLEMENTS_CREATE]);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.origin' => ['required', Rule::in(['pre_sale_collection', 'delivery_collection'])],
            'items.*.collection_id' => ['required', 'integer', 'distinct'],
        ]);
        $drafts->add($settlement, $request->user(), $data['items'], $data['idempotency_key']);

        return back()->with('success', 'Collections agregadas a la liquidación.');
    }

    public function removeItem(Request $request, RouteCashSettlement $settlement, RouteCashSettlementItem $item, RouteCashSettlementDraftService $drafts): RedirectResponse
    {
        $this->authorizeSettlement($request, $settlement, [Permissions::ROUTES_CASH_SETTLEMENTS_CREATE]);
        abort_unless((int) $item->route_cash_settlement_id === (int) $settlement->id, 403);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:120']]);
        $drafts->remove($settlement, $item, $request->user(), $data['idempotency_key']);

        return back()->with('success', 'Collection retirada del borrador.');
    }

    public function cancel(Request $request, RouteCashSettlement $settlement, RouteCashSettlementDraftService $drafts): RedirectResponse
    {
        $this->authorizeSettlement($request, $settlement, [Permissions::ROUTES_CASH_SETTLEMENTS_CREATE]);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'cancellation_reason' => ['required', 'string', 'max:2000'],
        ]);
        $drafts->cancel($settlement, $request->user(), $data['cancellation_reason'], $data['idempotency_key']);

        return redirect()->route('routes.cash-settlements.index')->with('success', 'Borrador de liquidación cancelado.');
    }

    public function confirm(Request $request, RouteCashSettlement $settlement, RouteCashSettlementService $settlements): RedirectResponse
    {
        $this->authorizeSettlement($request, $settlement, [Permissions::ROUTES_CASH_SETTLEMENTS_CONFIRM]);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'received_by' => ['required', 'integer', Rule::exists('users', 'id')],
            'received_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'variance_reason_code' => ['nullable', 'string', 'max:32'],
            'variance_explanation' => ['nullable', 'string', 'max:2000'],
            'confirm_variance' => ['nullable', 'boolean'],
        ]);
        $difference = round((float) $data['received_amount'] - (float) $settlement->expected_amount, 2);
        if ($difference !== 0.0) {
            $this->requirePermission($request, Permissions::ROUTES_CASH_SETTLEMENTS_CONFIRM_VARIANCE);
        }
        $this->receivedBy((int) $data['received_by'], $request->user());
        $settlements->confirm($settlement, $request->user(), (int) $data['received_by'], $data['received_amount'], $data['notes'] ?? null, $data['idempotency_key'], ['reason_code' => $data['variance_reason_code'] ?? null, 'explanation' => $data['variance_explanation'] ?? null, 'confirmed' => (bool) ($data['confirm_variance'] ?? false)]);

        return redirect()->route('routes.cash-settlements.show', $settlement)->with('success', 'Efectivo recibido y liquidación confirmada.');
    }

    private function authorizeSettlement(Request $request, RouteCashSettlement $settlement, array $permissions): void
    {
        $this->requireAnyPermission($request, $permissions);
        $actor = $request->user();
        abort_unless(
            (int) $settlement->business_id === currentBusinessId()
            && (int) $settlement->branch_id === (int) $actor->current_branch_id,
            403,
        );
    }

    private function requirePermission(Request $request, string $permission): void
    {
        $this->requireAnyPermission($request, [$permission]);
    }

    private function requireAnyPermission(Request $request, array $permissions): void
    {
        $actor = $request->user();
        abort_unless(
            $actor
            && $actor->is_active
            && (int) $actor->business_id === currentBusinessId()
            && collect($permissions)->contains(fn (string $permission) => Permissions::userHas($actor, $permission)),
            403,
        );
        BranchInventory::activeBranch(currentBusinessId());
    }

    private function collector(int $collectorId, User $actor): User
    {
        return User::query()
            ->whereKey($collectorId)
            ->where('business_id', currentBusinessId())
            ->where('current_branch_id', $actor->current_branch_id)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function receivedBy(int $receivedBy, User $actor): User
    {
        return $this->collector($receivedBy, $actor);
    }

    private function summary(RouteCashSettlement $settlement): array
    {
        return [
            'id' => $settlement->id,
            'status' => $settlement->status,
            'collector' => $settlement->collector ? ['id' => $settlement->collector->id, 'name' => $settlement->collector->name] : null,
            'expected_amount' => (float) $settlement->expected_amount,
            'received_amount' => $settlement->received_amount === null ? null : (float) $settlement->received_amount,
            'difference_amount' => $settlement->difference_amount === null ? null : (float) $settlement->difference_amount,
            'received_by_name' => $settlement->receivedBy?->name,
            'cash_register_session_id' => $settlement->cash_register_session_id,
            'created_at' => $settlement->created_at?->toIso8601String(),
        ];
    }

    private function itemPayload(RouteCashSettlementItem $item): array
    {
        $preSale = $item->preSaleCollection;
        $delivery = $item->deliveryCollection;
        $customer = $preSale?->preSale?->customer ?? $delivery?->sale?->customer;

        return [
            'id' => $item->id,
            'origin' => $preSale ? 'pre_sale_collection' : 'delivery_collection',
            'collection_id' => $preSale?->id ?? $delivery?->id,
            'amount' => (float) $item->amount_snapshot,
            'is_active' => $item->is_active,
            'customer_name' => $customer?->name,
            'collected_at' => ($preSale?->collected_at ?? $delivery?->collected_at)?->toIso8601String(),
        ];
    }

    private function userPayload(?int $userId, $names): ?array
    {
        if (! $userId || ! $names->has($userId)) {
            return null;
        }

        return ['id' => $userId, 'name' => $names->get($userId)];
    }

    private function eligiblePayload($collections): array
    {
        return $collections->map(function (array $collection) {
            return [
                ...$collection,
                'origin' => $collection['origin'] === 'route_pre_sale_collection' ? 'pre_sale_collection' : 'delivery_collection',
                'amount' => (float) $collection['amount'],
                'collected_at' => $collection['collected_at']?->toIso8601String(),
            ];
        })->values()->all();
    }
}
