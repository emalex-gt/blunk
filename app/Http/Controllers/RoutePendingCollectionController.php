<?php

namespace App\Http\Controllers;

use App\Models\RoutePendingCollectionCase;
use App\Models\Sale;
use App\Models\CashRegisterSession;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Routes\RoutePendingCollectionCaseService;
use App\Services\Routes\RoutePendingCollectionEligibility;
use App\Services\Routes\RoutePendingCollectionService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoutePendingCollectionController extends Controller
{
    public function index(Request $request, RoutePendingCollectionEligibility $eligibility): Response
    {
        $actor = $this->actor($request, Permissions::ROUTES_PENDING_COLLECTIONS_VIEW);
        $filters = $request->validate([
            'origin' => ['nullable', Rule::in(['external_reconciliation', 'in_app_stop'])],
            'aging' => ['nullable', Rule::in(['today', '1_3', '4_7', '8_plus'])],
            'assigned_to' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $filters = [
            'origin' => $filters['origin'] ?? null,
            'aging' => $filters['aging'] ?? null,
            'assigned_to' => isset($filters['assigned_to']) ? (int) $filters['assigned_to'] : null,
            'search' => $filters['search'] ?? null,
        ];
        $rows = $eligibility->queue(currentBusinessId(), (int) $actor->current_branch_id, $filters);
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = 25;
        $paginated = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('Routes/PendingCollections/Index', [
            'pending_collections' => $paginated,
            'summary' => ['count' => $rows->count(), 'amount' => (float) $rows->sum('amount')],
            'filters' => $filters,
            'can_manage' => Permissions::userHas($actor, Permissions::ROUTES_PENDING_COLLECTIONS_MANAGE),
            'can_collect' => Permissions::userHas($actor, Permissions::ROUTES_PENDING_COLLECTIONS_COLLECT),
            'users' => User::query()->where('business_id', currentBusinessId())->where('current_branch_id', $actor->current_branch_id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, RoutePendingCollectionCase $case): Response
    {
        $this->authorizeCase($request, $case, Permissions::ROUTES_PENDING_COLLECTIONS_VIEW);
        $case->load([
            'sale.customer:id,name,phone,address',
            'preSale:id,agreed_payment_method',
            'reconciliationItem',
            'stop.run:id,delivery_user_id,status',
            'events.recordedBy:id,name',
            'assignedTo:id,name',
            'originalDeliveryUser:id,name',
            'resolutionCollection.salePayment',
            'resolutionCollection.cashSession:id,status,opened_at,closed_at',
        ]);
        $actor = $request->user();

        return Inertia::render('Routes/PendingCollections/Show', [
            'case' => $case,
            'candidate' => null,
            'can_manage' => Permissions::userHas($actor, Permissions::ROUTES_PENDING_COLLECTIONS_MANAGE),
            'can_collect' => Permissions::userHas($actor, Permissions::ROUTES_PENDING_COLLECTIONS_COLLECT),
            'can_override' => Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_COLLECTIONS_OVERRIDE),
            'can_reverse' => Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_COLLECTIONS_REVERSE),
            'resolution_collection' => $case->resolutionCollection,
            ...$this->actionContext($actor),
        ]);
    }

    public function showSale(Request $request, Sale $sale, RoutePendingCollectionEligibility $eligibility): Response
    {
        $actor = $this->actor($request, Permissions::ROUTES_PENDING_COLLECTIONS_VIEW);
        abort_unless((int) $sale->business_id === currentBusinessId() && (int) $sale->branch_id === (int) $actor->current_branch_id, 403);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $sale->id)->first();
        if ($case) {
            return $this->show($request, $case);
        }
        $candidate = $eligibility->queue(currentBusinessId(), (int) $actor->current_branch_id)
            ->first(fn (array $row) => (int) $row['sale_id'] === (int) $sale->id && ! $row['is_persisted']);
        abort_unless($candidate, 404);
        $sale->load(['customer:id,name,phone,address', 'electronicDocument:id,sale_id,status']);

        return Inertia::render('Routes/PendingCollections/Show', [
            'case' => null,
            'candidate' => [...$candidate, 'sale' => $sale, 'events' => [], 'next_follow_up_at' => null, 'assigned_to' => null],
            'can_manage' => Permissions::userHas($actor, Permissions::ROUTES_PENDING_COLLECTIONS_MANAGE),
            'can_collect' => Permissions::userHas($actor, Permissions::ROUTES_PENDING_COLLECTIONS_COLLECT),
            'can_override' => Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_COLLECTIONS_OVERRIDE),
            'can_reverse' => Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_COLLECTIONS_REVERSE),
            'resolution_collection' => null,
            ...$this->actionContext($actor),
        ]);
    }

    public function event(Request $request, RoutePendingCollectionCase $case, RoutePendingCollectionCaseService $cases): RedirectResponse
    {
        $this->authorizeCase($request, $case, Permissions::ROUTES_PENDING_COLLECTIONS_MANAGE);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'type' => ['required', Rule::in(['note', 'contact', 'visit', 'promise', 'no_response', 'dispute'])],
            'note' => ['nullable', 'string', 'max:4000'],
            'occurred_at' => ['required', 'date'],
        ]);
        $cases->addEvent($case, $data, $request->user(), $data['idempotency_key']);

        return back()->with('success', 'Seguimiento registrado.');
    }

    public function assignment(Request $request, RoutePendingCollectionCase $case, RoutePendingCollectionCaseService $cases): RedirectResponse
    {
        $this->authorizeCase($request, $case, Permissions::ROUTES_PENDING_COLLECTIONS_MANAGE);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'next_follow_up_at' => ['nullable', 'date'],
        ]);
        $cases->updateAssignment($case, $data['assigned_to'] ?? null, $data['next_follow_up_at'] ?? null, $request->user(), $data['idempotency_key']);

        return back()->with('success', 'Responsable actualizado.');
    }

    public function collect(Request $request, RoutePendingCollectionCase $case, RoutePendingCollectionService $collections): RedirectResponse
    {
        $this->authorizeCase($request, $case, Permissions::ROUTES_PENDING_COLLECTIONS_COLLECT);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'transfer', 'check'])],
            'collected_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'collected_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'details' => ['nullable', 'array'],
            'override_reason' => ['nullable', 'string', 'max:2000'],
            'receive_cash_in_current_session' => ['nullable', 'boolean'],
        ]);
        $collections->collect($case, $data, $request->user(), $data['idempotency_key']);

        return redirect()->route('routes.pending-collections.show', $case)->with('success', 'Cobro posterior registrado.');
    }

    public function eventForSale(Request $request, Sale $sale, RoutePendingCollectionCaseService $cases): RedirectResponse
    {
        $actor = $this->actor($request, Permissions::ROUTES_PENDING_COLLECTIONS_MANAGE);
        $this->authorizeSale($sale, $actor);
        $data = $this->eventData($request);
        $result = $cases->addEventForEligibleSale($sale->id, $data, $actor, $data['idempotency_key']);
        $case = RoutePendingCollectionCase::query()->findOrFail($result->responsePayload['case_id']);
        return redirect()->route('routes.pending-collections.show', $case)->with('success', 'Seguimiento registrado.');
    }

    public function collectForSale(Request $request, Sale $sale, RoutePendingCollectionService $collections): RedirectResponse
    {
        $actor = $this->actor($request, Permissions::ROUTES_PENDING_COLLECTIONS_COLLECT);
        $this->authorizeSale($sale, $actor);
        $data = $this->collectionData($request);
        $collections->collectForEligibleSale($sale->id, $data, $actor, $data['idempotency_key']);
        $case = RoutePendingCollectionCase::query()->where('sale_id', $sale->id)->firstOrFail();
        return redirect()->route('routes.pending-collections.show', $case)->with('success', 'Cobro posterior registrado.');
    }

    private function eventData(Request $request): array
    {
        return $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'type' => ['required', Rule::in(['note', 'contact', 'visit', 'promise', 'no_response', 'dispute'])],
            'note' => ['nullable', 'string', 'max:4000'],
            'occurred_at' => ['required', 'date'],
        ]);
    }

    private function collectionData(Request $request): array
    {
        return $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'transfer', 'check'])],
            'collected_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'collected_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'details' => ['nullable', 'array'],
            'override_reason' => ['nullable', 'string', 'max:2000'],
            'receive_cash_in_current_session' => ['nullable', 'boolean'],
        ]);
    }

    private function actionContext(User $actor): array
    {
        $session = CashRegisterSession::query()->where('business_id', currentBusinessId())->where('branch_id', $actor->current_branch_id)->where('status', 'open')->latest('id')->first();
        return [
            'users' => User::query()->where('business_id', currentBusinessId())->where('current_branch_id', $actor->current_branch_id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'cash_policy' => TenantSetting::query()->where('business_id', currentBusinessId())->value('route_cash_custody_policy') ?? 'collector_custody_until_settlement',
            'open_cash_session' => $session ? ['id' => $session->id, 'opened_at' => $session->opened_at?->toIso8601String()] : null,
        ];
    }

    private function authorizeSale(Sale $sale, User $actor): void
    {
        abort_unless((int) $sale->business_id === currentBusinessId() && (int) $sale->branch_id === (int) $actor->current_branch_id, 403);
    }

    private function actor(Request $request, string $permission): User
    {
        $actor = $request->user();
        abort_unless($actor && $actor->is_active && (int) $actor->business_id === currentBusinessId() && Permissions::userHas($actor, $permission), 403);
        return $actor;
    }

    private function authorizeCase(Request $request, RoutePendingCollectionCase $case, string $permission): void
    {
        $actor = $this->actor($request, $permission);
        abort_unless((int) $case->business_id === currentBusinessId() && (int) $case->branch_id === (int) $actor->current_branch_id, 403);
    }
}
