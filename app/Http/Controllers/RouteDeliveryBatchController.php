<?php

namespace App\Http\Controllers;

use App\Models\RouteWorkDay;
use App\Models\RouteDeliveryBatch;
use App\Models\TenantSetting;
use App\Services\Routes\RouteDeliveryBatchService;
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
            'preSales.sale.payments:id,sale_id,method,amount',
        ]);

        $collectionResponsibility = TenantSetting::query()->where('business_id', $batch->business_id)->value('route_collection_responsibility') === 'delivery_agent' ? 'delivery_agent' : 'pre_seller';

        return Inertia::render('Routes/DeliveryBatches/Show', [
            'batch' => [...$this->payload($batch), 'pre_sales' => $batch->preSales->map(fn ($entry) => [
                'id' => $entry->id, 'status' => $entry->status, 'payment_method' => $entry->payment_method,
                'fel_dispatch_status' => $entry->fel_dispatch_status, 'error_message' => $entry->error_message,
                'pre_sale' => $entry->preSale, 'sale' => $entry->sale,
                'collection_responsibility' => $collectionResponsibility,
                'collection_status' => $collectionResponsibility === 'delivery_agent' ? 'pending_delivery_collection' : ($entry->preSale?->collections->first()?->status ?? 'pending_collection'),
                'collection' => $entry->preSale?->collections->first() ? [
                    'amount' => (float) $entry->preSale->collections->first()->amount,
                    'payment_method' => $entry->preSale->collections->first()->payment_method,
                    'collected_by' => $entry->preSale->collections->first()->collectedBy,
                    'recorded_by' => $entry->preSale->collections->first()->recordedBy,
                    'collected_at' => $entry->preSale->collections->first()->collected_at?->toIso8601String(),
                    'custody_status' => $entry->preSale->collections->first()->custody_status,
                ] : null,
            ])->values()],
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
            'total_items' => $batch->total_items, 'total_amount' => (float) $batch->total_amount,
            'branch' => $batch->branch, 'zone' => $batch->zone, 'delivered_by' => $batch->deliveredBy, 'work_day' => $batch->workDay,
        ];
    }
}
