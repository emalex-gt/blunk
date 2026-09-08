<?php

namespace App\Http\Controllers;

use App\Models\RouteDeliveryRun;
use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryStop;
use App\Models\User;
use App\Services\Routes\RouteDeliveryRunAssignmentService;
use App\Services\Routes\RouteDeliveryRunService;
use App\Support\BranchInventory;
use App\Support\IdempotencyService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RouteDeliveryRunController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requirePermission($request, Permissions::ROUTES_DELIVERY_RUNS_VIEW);
        $actor = $request->user();
        $query = RouteDeliveryRun::query()->where('business_id', currentBusinessId())->where('branch_id', $actor->current_branch_id)
            ->with('deliveryUser:id,name')->withCount('stops');
        if (! Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_MANAGE)) $query->where('delivery_user_id', $actor->id);
        $runs = $query->latest('id')->paginate(30)->through(fn (RouteDeliveryRun $run) => $this->runSummary($run));
        return Inertia::render('Routes/Mobile/DeliveryRuns/Index', ['runs' => $runs, 'can_manage' => Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_MANAGE)]);
    }

    public function show(Request $request, RouteDeliveryRun $run, RouteDeliveryRunService $runs): Response
    {
        $this->authorizeRun($request, $run, Permissions::ROUTES_DELIVERY_RUNS_VIEW);
        $run->load(['deliveryUser:id,name', 'stops' => fn ($q) => $q->with(['sale:id,business_id,business_number,total,payment_status,payment_method,certification_status,electronic_document_id', 'sale.electronicDocument:id,sale_id,status', 'deliveryCollection.collectedBy:id,name'])->orderBy('position')->orderBy('id')]);
        $actor = $request->user();
        return Inertia::render('Routes/Mobile/DeliveryRuns/Show', [
            'run' => [...$this->runSummary($run), 'stops' => $run->stops->map(fn (RouteDeliveryStop $stop) => $this->stopPayload($stop))->values(), 'progress' => $runs->progress($run)],
            'can_manage' => Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_MANAGE),
            'can_execute' => (int) $run->delivery_user_id === (int) $actor->id && Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_EXECUTE),
            'can_correct' => Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_CORRECT),
        ]);
    }

    public function store(Request $request, RouteDeliveryRunAssignmentService $assignments): RedirectResponse
    {
        $this->requirePermission($request, Permissions::ROUTES_DELIVERY_RUNS_MANAGE);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:120'], 'delivery_user_id' => ['required', 'integer', Rule::exists('users', 'id')], 'entry_ids' => ['required', 'array', 'min:1'], 'entry_ids.*' => ['integer', 'distinct']]);
        $deliveryUser = User::query()->findOrFail($data['delivery_user_id']);
        $result = $this->idempotent($request, 'route_delivery_run_create', $data, fn () => $assignments->createDraft($request->user(), $deliveryUser, $data['entry_ids'])->id);
        $run = RouteDeliveryRun::query()->findOrFail($result->resultId);
        return redirect()->route('routes.delivery-runs.show', $run)->with('success', 'Jornada borrador creada.');
    }

    public function assign(Request $request, RouteDeliveryRun $run, RouteDeliveryRunAssignmentService $assignments): RedirectResponse
    {
        $this->authorizeRun($request, $run, Permissions::ROUTES_DELIVERY_RUNS_MANAGE);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:120'], 'entry_ids' => ['required', 'array', 'min:1'], 'entry_ids.*' => ['integer', 'distinct']]);
        $this->idempotent($request, 'route_delivery_run_assign', ['run_id' => $run->id, ...$data], fn () => tap($run->id, fn () => $assignments->assignEntries($run, $data['entry_ids'], $request->user())));
        return back()->with('success', 'Comprobantes asignados.');
    }

    public function assignBatch(Request $request, RouteDeliveryRun $run, RouteDeliveryBatch $batch, RouteDeliveryRunAssignmentService $assignments): RedirectResponse
    {
        $this->authorizeRun($request, $run, Permissions::ROUTES_DELIVERY_RUNS_MANAGE);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:120']]);
        $result = $this->idempotent($request, 'route_delivery_run_assign_batch', ['run_id' => $run->id, 'batch_id' => $batch->id, ...$data], fn () => ['result_id' => $run->id, 'response_payload' => $assignments->assignBatch($run, $batch, $request->user())]);
        $skipped = count($result->responsePayload['skipped'] ?? []);
        return back()->with('success', $skipped ? "Lote asignado con {$skipped} comprobantes no elegibles omitidos." : 'Lote asignado.');
    }

    public function remove(Request $request, RouteDeliveryRun $run, RouteDeliveryStop $stop, RouteDeliveryRunAssignmentService $assignments): RedirectResponse
    {
        $this->authorizeRun($request, $run, Permissions::ROUTES_DELIVERY_RUNS_MANAGE);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:120']]);
        $this->idempotent($request, 'route_delivery_run_remove_stop', ['run_id' => $run->id, 'stop_id' => $stop->id, ...$data], fn () => tap($stop->id, fn () => $assignments->removeStop($run, $stop, $request->user())));
        return back()->with('success', 'Parada retirada del borrador.');
    }

    public function reorder(Request $request, RouteDeliveryRun $run, RouteDeliveryRunAssignmentService $assignments): RedirectResponse
    {
        $this->authorizeRun($request, $run, Permissions::ROUTES_DELIVERY_RUNS_MANAGE);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:120'], 'stop_ids' => ['required', 'array', 'min:1'], 'stop_ids.*' => ['integer', 'distinct']]);
        $this->idempotent($request, 'route_delivery_run_reorder', ['run_id' => $run->id, ...$data], fn () => tap($run->id, fn () => $assignments->reorder($run, $data['stop_ids'], $request->user())));
        return back()->with('success', 'Orden de paradas actualizado.');
    }

    public function start(Request $request, RouteDeliveryRun $run, RouteDeliveryRunService $runs): RedirectResponse
    {
        $this->authorizeRun($request, $run, Permissions::ROUTES_DELIVERY_RUNS_EXECUTE, true);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:120']]);
        $runs->start($run, $request->user(), $data['idempotency_key']);
        return back()->with('success', 'Jornada iniciada.');
    }

    public function close(Request $request, RouteDeliveryRun $run, RouteDeliveryRunService $runs): RedirectResponse
    {
        $this->authorizeRun($request, $run, Permissions::ROUTES_DELIVERY_RUNS_EXECUTE, true);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:120'], 'confirm_unpaid' => ['nullable', 'boolean']]);
        $runs->close($run, $request->user(), $data['idempotency_key'], (bool) ($data['confirm_unpaid'] ?? false));
        return redirect()->route('routes.delivery-runs.show', $run)->with('success', 'Jornada cerrada.');
    }

    private function authorizeRun(Request $request, RouteDeliveryRun $run, string $permission, bool $assignedOnly = false): void
    {
        $this->requirePermission($request, $permission);
        $actor = $request->user();
        abort_unless((int) $run->business_id === currentBusinessId() && (int) $run->branch_id === (int) $actor->current_branch_id, 403);
        if ($assignedOnly || ! Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_MANAGE)) abort_unless((int) $run->delivery_user_id === (int) $actor->id, 403);
    }

    private function requirePermission(Request $request, string $permission): void
    {
        $actor = $request->user();
        abort_unless($actor->is_active && (int) $actor->business_id === currentBusinessId() && Permissions::userHas($actor, $permission), 403);
        BranchInventory::activeBranch(currentBusinessId());
    }

    private function idempotent(Request $request, string $operation, array $payload, callable $action)
    {
        $actor = $request->user();
        return app(IdempotencyService::class)->run((int) $actor->business_id, (int) $actor->current_branch_id, $actor->id, $operation, $payload['idempotency_key'], $payload, function () use ($action) { $result = $action(); return is_array($result) && array_key_exists('result_id', $result) ? $result : ['result_id' => $result, 'response_payload' => []]; }, 'route_delivery_run');
    }

    private function runSummary(RouteDeliveryRun $run): array { return ['id' => $run->id, 'status' => $run->status, 'delivery_user' => $run->deliveryUser ? ['id' => $run->deliveryUser->id, 'name' => $run->deliveryUser->name] : null, 'stops_count' => $run->stops_count ?? $run->stops()->count(), 'started_at' => $run->started_at?->toIso8601String(), 'closed_at' => $run->closed_at?->toIso8601String(), 'created_at' => $run->created_at?->toIso8601String()]; }
    private function stopPayload(RouteDeliveryStop $stop): array { $sale = $stop->sale; return ['id' => $stop->id, 'position' => $stop->position, 'status' => $stop->status, 'customer_name' => $stop->customer_name_snapshot, 'customer_address' => $stop->customer_address_snapshot, 'customer_phone' => $stop->customer_phone_snapshot, 'delivery_notes' => $stop->delivery_notes, 'not_delivered_reason_code' => $stop->not_delivered_reason_code, 'collection_responsibility' => $stop->collection_responsibility_snapshot, 'sale' => $sale ? ['id' => $sale->id, 'number' => $sale->business_number, 'total' => (float) $sale->total, 'payment_status' => $sale->payment_status, 'payment_method' => $sale->payment_method, 'fel_status' => $sale->electronicDocument?->status ?? $sale->certification_status] : null, 'collection' => $stop->deliveryCollection ? ['id' => $stop->deliveryCollection->id, 'payment_method' => $stop->deliveryCollection->payment_method, 'collected_at' => $stop->deliveryCollection->collected_at?->toIso8601String(), 'collected_by' => $stop->deliveryCollection->collectedBy?->name] : null]; }
}
