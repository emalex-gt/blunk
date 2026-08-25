<?php

namespace App\Http\Controllers;

use App\Models\PreSale;
use App\Models\RoutePreparationBatch;
use App\Models\RouteWorkDay;
use App\Services\Routes\RoutePreparationBatchService;
use App\Services\Routes\RoutePreparationDocuments;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoutePreparationBatchController extends Controller
{
    public function prepareAll(Request $request, RouteWorkDay $workDay, RoutePreparationBatchService $batches): RedirectResponse
    {
        $this->authorizeWorkDayPreparation($request, $workDay);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);

        $result = $batches->prepareAll($workDay, $request->user(), $data['idempotency_key']);

        return redirect()
            ->route('routes.preparation-batches.show', $result->resultId)
            ->with('success', $result->replayed ? 'La preparación masiva ya había sido completada.' : 'Todas las preventas disponibles fueron preparadas.');
    }

    public function index(Request $request): Response
    {
        $businessId = currentBusinessId();

        $batches = RoutePreparationBatch::query()
            ->where('business_id', $businessId)
            ->where('branch_id', BranchInventory::activeBranch($businessId)->id)
            ->with(['branch:id,name', 'zone:id,name', 'preparedBy:id,name', 'workDay:id,work_date'])
            ->latest('prepared_at')
            ->latest('id')
            ->paginate(25)
            ->through(fn (RoutePreparationBatch $batch) => $this->batchPayload($batch));

        return Inertia::render('Routes/PreparationBatches/Index', [
            'batches' => $batches,
        ]);
    }

    public function show(Request $request, RoutePreparationBatch $batch): Response
    {
        $this->authorizeBatch($request, $batch);

        $batch->load([
            'branch:id,name', 'zone:id,name', 'preparedBy:id,name', 'workDay:id,work_date',
            'preSales.preSale.customer:id,name,commercial_name,doc_number',
            'preSales.preSale.convertedSale:id,business_number,total,document_type',
        ]);

        return Inertia::render('Routes/PreparationBatches/Show', [
            'batch' => [
                ...$this->batchPayload($batch),
                'pre_sales' => $batch->preSales->map(fn ($entry) => [
                    'id' => $entry->id,
                    'status' => $entry->status,
                    'total_items' => $entry->total_items,
                    'total_amount' => (float) $entry->total_amount,
                    'pre_sale' => $entry->preSale ? [
                        'id' => $entry->preSale->id,
                        'status' => $entry->preSale->status,
                        'customer' => $entry->preSale->customer,
                        'converted_sale' => $entry->preSale->convertedSale,
                    ] : null,
                ])->values(),
            ],
        ]);
    }

    public function consolidated(Request $request, RoutePreparationBatch $batch, RoutePreparationDocuments $documents)
    {
        $this->authorizeBatch($request, $batch);
        $batch = $documents->batch($batch);
        $batch->update(['documents_generated_at' => now()]);

        return Pdf::loadView('pdf.route-preparation-batches.consolidated', [
            'batch' => $batch,
            'customers' => $documents->customers($batch),
        ])->setPaper('letter')->download("preparacion-lote-{$batch->id}-consolidado.pdf");
    }

    public function receipts(Request $request, RoutePreparationBatch $batch, RoutePreparationDocuments $documents)
    {
        $this->authorizeBatch($request, $batch);
        $batch = $documents->batch($batch);
        $batch->update(['documents_generated_at' => now()]);

        return Pdf::loadView('pdf.route-preparation-batches.receipts', [
            'batch' => $batch,
        ])->setPaper('letter')->download("preparacion-lote-{$batch->id}-recibos.pdf");
    }

    public function products(Request $request, RoutePreparationBatch $batch, RoutePreparationDocuments $documents)
    {
        $this->authorizeBatch($request, $batch);
        $batch = $documents->batch($batch);
        $batch->update(['documents_generated_at' => now()]);

        return Pdf::loadView('pdf.route-preparation-batches.products', [
            'batch' => $batch,
            'products' => $documents->products($batch),
        ])->setPaper('letter')->download("preparacion-lote-{$batch->id}-productos.pdf");
    }

    private function authorizeWorkDayPreparation(Request $request, RouteWorkDay $workDay): void
    {
        abort_unless((int) $workDay->business_id === currentBusinessId(), 403);
        abort_unless(Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_PICK), 403);
        abort_unless((int) BranchInventory::activeBranch((int) $workDay->business_id)->id === (int) $workDay->branch_id, 403);
    }

    private function authorizeBatch(Request $request, RoutePreparationBatch $batch): void
    {
        abort_unless((int) $batch->business_id === currentBusinessId(), 403);
        abort_unless(Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_ADMIN_VIEW), 403);
        abort_unless((int) BranchInventory::activeBranch((int) $batch->business_id)->id === (int) $batch->branch_id, 403);
    }

    private function batchPayload(RoutePreparationBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'status' => $batch->status,
            'prepared_at' => $batch->prepared_at?->toIso8601String(),
            'stock_deduction_timing' => $batch->stock_deduction_timing,
            'invoicing_mode' => $batch->invoicing_mode,
            'total_pre_sales' => $batch->total_pre_sales,
            'total_items' => $batch->total_items,
            'total_amount' => (float) $batch->total_amount,
            'documents_generated_at' => $batch->documents_generated_at?->toIso8601String(),
            'branch' => $batch->branch,
            'zone' => $batch->zone,
            'prepared_by' => $batch->preparedBy,
            'work_day' => $batch->workDay,
        ];
    }
}
