<?php

namespace App\Http\Controllers;

use App\Models\RouteDeliveryStop;
use App\Services\Routes\RouteDeliveryStopCorrectionService;
use App\Services\Routes\RouteDeliveryStopService;
use App\Services\Routes\RouteOperationReturnService;
use App\Support\BranchInventory;
use App\Support\IdempotencyService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RouteDeliveryStopController extends Controller
{
    public function show(Request $request, RouteDeliveryStop $stop): Response
    {
        $this->authorizeStop($request, $stop, Permissions::ROUTES_DELIVERY_RUNS_VIEW);
        $stop->load(['run.deliveryUser:id,name', 'entry.batch', 'sale:id,business_id,business_number,total,status,payment_status,amount_paid,payment_method,certification_status,electronic_document_id,fel_certified_at,fel_uuid,fel_number', 'sale.electronicDocument:id,sale_id,status', 'sale.items:id,sale_id,product_name,quantity', 'sale.payments', 'deliveryCollection.collectedBy:id,name', 'operationReturn:id,route_delivery_stop_id,route_delivery_batch_pre_sale_id,status,reason,goods_received_at,completed_at', 'operationReturn.refund']);
        $actor = $request->user();
        $returnContext = $stop->entry ? app(RouteOperationReturnService::class)->previewForEntry($stop->entry) : ['eligible' => false, 'requires_cash_refund' => false];
        return Inertia::render('Routes/Mobile/DeliveryRuns/Stop', ['stop' => [...$this->payload($stop), 'return_context' => $returnContext], 'current_user_id' => $actor->id, 'can_execute' => $stop->run->status === 'open' && (int) $stop->run->delivery_user_id === (int) $actor->id && Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_EXECUTE), 'can_correct' => Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_CORRECT), 'can_override_collector' => Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_COLLECTIONS_OVERRIDE), 'can_return' => $stop->status === 'delivered' && $stop->operationReturn === null && $returnContext['eligible'] && Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_CORRECT)]);
    }

    public function complete(Request $request, RouteDeliveryStop $stop, RouteDeliveryStopService $stops): RedirectResponse
    {
        $this->authorizeStop($request, $stop, Permissions::ROUTES_DELIVERY_RUNS_EXECUTE, true);
        $data = $request->validate($this->outcomeRules() + ['idempotency_key' => ['required', 'string', 'min:8', 'max:120'], 'collected' => ['nullable', 'boolean'], 'amount' => ['nullable', 'numeric', 'min:0.01'], 'payment_method' => ['nullable', Rule::in(['cash', 'card', 'transfer', 'check'])], 'reference' => ['nullable', 'string', 'max:255'], 'collected_by' => ['nullable', 'integer', Rule::exists('users', 'id')], 'collected_at' => ['nullable', 'date'], 'override_reason' => ['nullable', 'string', 'max:2000'], 'receive_cash_in_current_session' => ['nullable', 'boolean']]);
        if ((bool) ($data['collected'] ?? false)) $data = [...$data, 'collected_by' => $data['collected_by'] ?? $request->user()->id, 'collected_at' => $data['collected_at'] ?? now()->toIso8601String()];
        $stops->complete($stop, $data, $request->user(), $data['idempotency_key']);
        return redirect()->route('routes.delivery-stops.show', $stop)->with('success', 'Resultado de entrega registrado.');
    }

    public function correct(Request $request, RouteDeliveryStop $stop, RouteDeliveryStopCorrectionService $corrections): RedirectResponse
    {
        $this->authorizeStop($request, $stop, Permissions::ROUTES_DELIVERY_RUNS_CORRECT);
        $data = $request->validate($this->outcomeRules() + ['idempotency_key' => ['required', 'string', 'min:8', 'max:120'], 'correction_reason' => ['required', 'string', 'max:2000']]);
        $actor = $request->user();
        app(IdempotencyService::class)->run((int) $actor->business_id, (int) $actor->current_branch_id, $actor->id, 'route_delivery_stop_correct', $data['idempotency_key'], ['stop_id' => $stop->id, ...$data], fn () => ['result_id' => $corrections->correct($stop, $data, $actor)->id, 'response_payload' => []], 'route_delivery_stop');
        return back()->with('success', 'Corrección auditada registrada.');
    }

    public function collect(Request $request, RouteDeliveryStop $stop, RouteDeliveryStopService $stops): RedirectResponse
    {
        $this->authorizeStop($request, $stop, Permissions::ROUTES_DELIVERY_RUNS_EXECUTE, true);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:120'], 'amount' => ['required', 'numeric', 'min:0.01'], 'payment_method' => ['required', Rule::in(['cash', 'card', 'transfer', 'check'])], 'reference' => ['nullable', 'string', 'max:255'], 'collected_by' => ['nullable', 'integer', Rule::exists('users', 'id')], 'collected_at' => ['required', 'date'], 'override_reason' => ['nullable', 'string', 'max:2000'], 'receive_cash_in_current_session' => ['nullable', 'boolean']]);
        $data = [...$data, 'collected_by' => $data['collected_by'] ?? $request->user()->id, 'collected_at' => $data['collected_at'] ?? now()->toIso8601String()];
        $stops->collect($stop, $data, $request->user(), $data['idempotency_key']);
        return redirect()->route('routes.delivery-stops.show', $stop)->with('success', 'Cobro registrado.');
    }

    private function authorizeStop(Request $request, RouteDeliveryStop $stop, string $permission, bool $assignedOnly = false): void
    {
        $actor = $request->user();
        abort_unless($actor->is_active && (int) $actor->business_id === currentBusinessId() && Permissions::userHas($actor, $permission), 403);
        abort_unless((int) $stop->business_id === currentBusinessId() && (int) $stop->branch_id === (int) $actor->current_branch_id, 403);
        abort_unless((int) BranchInventory::activeBranch(currentBusinessId())->id === (int) $stop->branch_id, 403);
        $stop->loadMissing('run');
        // Execution is always restricted to the assigned deliverer. A user with
        // the explicit correction permission is an administrator for this
        // narrow audited operation; it must not implicitly require manage.
        if ($assignedOnly || (! Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_RUNS_MANAGE) && $permission !== Permissions::ROUTES_DELIVERY_RUNS_CORRECT)) {
            abort_unless((int) $stop->run->delivery_user_id === (int) $actor->id, 403);
        }
    }

    private function outcomeRules(): array { return ['delivery_status' => ['required', Rule::in(['delivered', 'not_delivered'])], 'not_delivered_reason_code' => ['nullable', Rule::in(['customer_absent', 'customer_rejected', 'address_issue', 'business_closed', 'damaged_goods', 'other'])], 'delivery_notes' => ['nullable', 'string', 'max:2000']]; }
    private function payload(RouteDeliveryStop $stop): array { $sale = $stop->sale; return ['id' => $stop->id, 'run_id' => $stop->route_delivery_run_id, 'run_status' => $stop->run->status, 'status' => $stop->status, 'completed_at' => $stop->completed_at?->toIso8601String(), 'customer_name' => $stop->customer_name_snapshot, 'customer_address' => $stop->customer_address_snapshot, 'customer_phone' => $stop->customer_phone_snapshot, 'delivery_notes' => $stop->delivery_notes, 'not_delivered_reason_code' => $stop->not_delivered_reason_code, 'collection_responsibility' => $stop->collection_responsibility_snapshot, 'sale' => $sale ? ['number' => $sale->business_number, 'total' => (float) $sale->total, 'status' => $sale->status, 'payment_status' => $sale->payment_status, 'payment_method' => $sale->payment_method, 'fel_status' => $sale->electronicDocument?->status ?? $sale->certification_status, 'items' => $sale->items->map(fn ($item) => ['product_name' => $item->product_name, 'quantity' => (float) $item->quantity])] : null, 'collection' => $stop->deliveryCollection ? ['payment_method' => $stop->deliveryCollection->payment_method, 'collected_at' => $stop->deliveryCollection->collected_at?->toIso8601String(), 'collected_by' => $stop->deliveryCollection->collectedBy?->name] : null, 'operation_return' => $stop->operationReturn ? ['status' => $stop->operationReturn->status, 'reason' => $stop->operationReturn->reason, 'completed_at' => $stop->operationReturn->completed_at?->toIso8601String(), 'refund' => $stop->operationReturn->refund ? ['amount' => (float) $stop->operationReturn->refund->amount, 'method' => $stop->operationReturn->refund->refund_method, 'status' => $stop->operationReturn->refund->status, 'refunded_at' => $stop->operationReturn->refund->refunded_at?->toIso8601String()] : null] : null]; }
}
