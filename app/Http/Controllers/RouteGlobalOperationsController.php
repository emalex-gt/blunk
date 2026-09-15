<?php

namespace App\Http\Controllers;

use App\Models\TenantSetting;
use App\Services\Routes\RouteGlobalOperationsService;
use App\Services\Routes\RouteGlobalPreparationDocuments;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RouteGlobalOperationsController extends Controller
{
    public function index(Request $request, RouteGlobalOperationsService $operations): Response
    {
        [$businessId, $branchId] = $this->scope($request);

        return Inertia::render('Routes/GlobalOperations/Index', [
            'preparation_preview' => $operations->preparationPreview($businessId, $branchId),
            'sales_preview' => $operations->salesPreview($businessId, $branchId),
            'stock_deduction_timing' => TenantSetting::query()->where('business_id', $businessId)->value('route_pre_sale_stock_deduction_timing') === 'picking' ? 'picking' : 'invoice',
            'fel_enabled' => (bool) config('fel.route_automation_enabled'),
        ]);
    }

    public function prepare(Request $request, RouteGlobalOperationsService $operations): RedirectResponse
    {
        $this->scope($request, Permissions::ROUTES_PRE_SALES_PICK);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:120']]);
        $result = $operations->prepareAll($request->user(), $data['idempotency_key']);

        return back()->with('global_preparation_result', $result);
    }

    public function generateSales(Request $request, RouteGlobalOperationsService $operations): RedirectResponse
    {
        $this->scope($request, Permissions::ROUTES_PRE_SALES_PICK);
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:120']]);
        $result = $operations->generateSales($request->user(), $data['idempotency_key']);

        return back()->with('global_sales_result', $result);
    }

    public function consolidated(Request $request, RouteGlobalPreparationDocuments $documents)
    {
        [$businessId, $branchId] = $this->scope($request);
        $document = $documents->forBatches($businessId, $branchId, $this->batchIds($request));

        return Pdf::loadView('pdf.route-global-preparation.consolidated', compact('document'))
            ->setPaper('letter')
            ->download('preparacion-global-consolidado.pdf');
    }

    public function products(Request $request, RouteGlobalPreparationDocuments $documents)
    {
        [$businessId, $branchId] = $this->scope($request);
        $document = $documents->forBatches($businessId, $branchId, $this->batchIds($request));

        return Pdf::loadView('pdf.route-global-preparation.products', compact('document'))
            ->setPaper('letter')
            ->download('preparacion-global-productos.pdf');
    }

    public function receipts(Request $request, RouteGlobalPreparationDocuments $documents)
    {
        [$businessId, $branchId] = $this->scope($request);
        $document = $documents->forBatches($businessId, $branchId, $this->batchIds($request));

        return Pdf::loadView('pdf.route-global-preparation.receipts', compact('document'))
            ->setPaper([0, 0, 396, 612])
            ->download('preparacion-global-recibos.pdf');
    }

    private function batchIds(Request $request): array
    {
        return $request->validate(['batch_ids' => ['required', 'array', 'min:1'], 'batch_ids.*' => ['integer', 'distinct']])['batch_ids'];
    }

    /** @return array{int,int} */
    private function scope(Request $request, ?string $requiredPermission = null): array
    {
        $businessId = (int) currentBusinessId();
        abort_unless($businessId > 0, 403);
        abort_unless(Permissions::userHas($request->user(), $requiredPermission ?? Permissions::ROUTES_PRE_SALES_ADMIN_VIEW), 403);
        $branchId = (int) BranchInventory::activeBranch($businessId)->id;
        abort_unless((int) $request->user()->business_id === $businessId && (int) $request->user()->current_branch_id === $branchId && (bool) $request->user()->is_active, 403);

        return [$businessId, $branchId];
    }
}
