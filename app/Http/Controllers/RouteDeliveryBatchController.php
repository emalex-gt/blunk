<?php

namespace App\Http\Controllers;

use App\Models\RouteWorkDay;
use App\Models\RouteDeliveryBatch;
use App\Models\User;
use App\Services\Routes\RouteDeliveryBatchService;
use App\Services\Routes\ExternalDeliveryEligibility;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RouteDeliveryBatchController extends Controller
{
    public function index(): Response
    {
        $businessId = currentBusinessId();
        $branchId = BranchInventory::activeBranch($businessId)->id;
        $batches = RouteDeliveryBatch::query()->where('business_id', $businessId)->where('branch_id', $branchId)
            ->with(['branch:id,name', 'zone:id,name', 'deliveredBy:id,name', 'workDay:id,work_date'])
            ->latest('delivered_at')->latest('id')->paginate(25)
            ->through(fn (RouteDeliveryBatch $batch) => $this->payload($batch));

        return Inertia::render('Routes/DeliveryBatches/Index', ['batches' => $batches]);
    }

    public function show(RouteDeliveryBatch $batch): Response
    {
        $this->authorizeBatch($batch);
        $batch->load([
            'branch:id,name', 'zone:id,name', 'deliveredBy:id,name', 'workDay:id,work_date',
            'preSales.preSale.customer:id,name,commercial_name,doc_number',
            'preSales.preSale:id,status,fel_eligibility_status,fel_eligibility_reason,customer_id',
            'preSales.preSale.collections' => fn ($query) => $query->whereIn('status', ['captured', 'linked'])->with(['collectedBy:id,name', 'recordedBy:id,name']),
            'preSales.sale:id,business_id,business_number,total,document_type,payment_status,payment_method,certification_status,electronic_document_id',
            'preSales.sale.electronicDocument:id,sale_id,status,error_message',
            'preSales.sale.payments:id,sale_id,method,amount,collected_by,collected_at,route_pre_sale_collection_id,route_delivery_collection_id',
            'preSales.externalDeliveryReconciliationItem.deliveryCollection.collectedBy:id,name',
        ]);

        $eligibility = app(ExternalDeliveryEligibility::class);
        $reconciled = $batch->preSales->filter(fn ($entry) => $entry->externalDeliveryReconciliationItem !== null)->count();
        $canOverrideCollector = Permissions::userHas(request()->user(), Permissions::ROUTES_EXTERNAL_DELIVERY_COLLECTION_OVERRIDE);
        return Inertia::render('Routes/DeliveryBatches/Show', [
            'can_reconcile_external_delivery' => $batch->isExternalDeliveryTracking() && Permissions::userHas(request()->user(), Permissions::ROUTES_EXTERNAL_DELIVERY_RECONCILE),
            'can_override_external_delivery_collector' => $canOverrideCollector,
            'branch_collectors' => $canOverrideCollector
                ? User::query()->where('business_id', $batch->business_id)->where('current_branch_id', $batch->branch_id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'current_user_id' => request()->user()->id,
            'batch' => [...$this->payload($batch), 'pre_sales' => $batch->preSales->map(function ($entry) use ($eligibility) {
                $context = $eligibility->forEntry($entry);
                $collectionResponsibility = $context['responsibility'] ?? 'review_required';
                $preSaleCollection = $entry->preSale?->collections->first();
                $deliveryCollection = $entry->externalDeliveryReconciliationItem?->deliveryCollection;

                return [
                'id' => $entry->id, 'status' => $entry->status, 'payment_method' => $entry->payment_method,
                'fel_dispatch_status' => $entry->fel_dispatch_status, 'error_message' => $entry->error_message,
                'pre_sale' => $entry->preSale, 'sale' => $entry->sale,
                'collection_responsibility' => $collectionResponsibility,
                'collection_status' => $collectionResponsibility === 'delivery_agent' ? 'pending_delivery_collection' : ($collectionResponsibility === 'review_required' ? 'review_required' : ($entry->preSale?->collections->first()?->status ?? 'pending_collection')),
                'collection' => $preSaleCollection ? [
                    'amount' => (float) $preSaleCollection->amount,
                    'payment_method' => $preSaleCollection->payment_method,
                    'collected_by' => $preSaleCollection->collectedBy,
                    'recorded_by' => $preSaleCollection->recordedBy,
                    'collected_at' => $preSaleCollection->collected_at?->toIso8601String(),
                    'custody_status' => $preSaleCollection->custody_status,
                ] : null,
                'financial_collection' => $deliveryCollection ? [
                    'payment_method' => $deliveryCollection->payment_method,
                    'collected_by' => $deliveryCollection->collectedBy,
                    'collected_at' => $deliveryCollection->collected_at?->toIso8601String(),
                    'custody_status' => $deliveryCollection->custody_status,
                ] : null,
                'reconciliation' => $entry->externalDeliveryReconciliationItem ? ['id' => $entry->externalDeliveryReconciliationItem->id, 'delivery_status' => $entry->externalDeliveryReconciliationItem->delivery_status, 'not_delivered_reason' => $entry->externalDeliveryReconciliationItem->not_delivered_reason, 'notes' => $entry->externalDeliveryReconciliationItem->notes] : null,
                'external_eligibility' => $context,
                ];
            })->values(), 'reconciliation_progress' => ['total' => $batch->preSales->count(), 'reconciled' => $reconciled, 'pending' => $batch->preSales->count() - $reconciled]],
        ]);
    }

    public function deliverAll(Request $request, RouteWorkDay $workDay, RouteDeliveryBatchService $deliveries): RedirectResponse
    {
        abort_unless((int) $workDay->business_id === currentBusinessId(), 403);
        abort_unless(Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_PICK), 403);
        abort_unless((int) BranchInventory::activeBranch((int) $workDay->business_id)->id === (int) $workDay->branch_id, 403);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:120']]);
        $result = $deliveries->deliverAll($workDay, $request->user(), $data['idempotency_key']);

        return redirect()->route('routes.work-days.show', $workDay)
            ->with('success', $result->replayed ? 'La entrega masiva ya había sido completada.' : 'Las preventas preparadas fueron entregadas y registradas.');
    }

    private function authorizeBatch(RouteDeliveryBatch $batch): void
    {
        abort_unless((int) $batch->business_id === currentBusinessId(), 403);
        abort_unless(Permissions::userHas(request()->user(), Permissions::ROUTES_PRE_SALES_ADMIN_VIEW), 403);
        abort_unless((int) BranchInventory::activeBranch((int) $batch->business_id)->id === (int) $batch->branch_id, 403);
    }

    private function payload(RouteDeliveryBatch $batch): array
    {
        return [
            'id' => $batch->id, 'status' => $batch->status, 'delivered_at' => $batch->delivered_at?->toIso8601String(),
            'stock_deduction_timing' => $batch->stock_deduction_timing, 'invoicing_mode' => $batch->invoicing_mode,
            'fel_automation_enabled' => $batch->fel_automation_enabled, 'total_pre_sales' => $batch->total_pre_sales,
            'delivery_tracking_snapshot' => $batch->delivery_tracking_snapshot,
            'collection_responsibility_snapshot' => $batch->collection_responsibility_snapshot,
            'total_items' => $batch->total_items, 'total_amount' => (float) $batch->total_amount,
            'branch' => $batch->branch, 'zone' => $batch->zone, 'delivered_by' => $batch->deliveredBy, 'work_day' => $batch->workDay,
        ];
    }
}
