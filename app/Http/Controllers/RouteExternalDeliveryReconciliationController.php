<?php

namespace App\Http\Controllers;

use App\Models\RouteDeliveryBatch;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RouteExternalDeliveryReconciliationItem;
use App\Services\Routes\RouteExternalDeliveryReconciliationCorrectionService;
use App\Services\Routes\RouteExternalDeliveryReconciliationService;
use App\Support\BranchInventory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RouteExternalDeliveryReconciliationController extends Controller
{
    public function store(Request $request, RouteDeliveryBatch $batch, RouteDeliveryBatchPreSale $entry, RouteExternalDeliveryReconciliationService $reconciliations): RedirectResponse
    {
        abort_unless((int) $batch->business_id === currentBusinessId() && (int) $entry->route_delivery_batch_id === (int) $batch->id, 403);
        abort_unless((int) BranchInventory::activeBranch((int) $batch->business_id)->id === (int) $batch->branch_id, 403);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'], 'delivery_status' => ['required', Rule::in(['delivered', 'not_delivered'])],
            'not_delivered_reason' => ['nullable', Rule::in(['customer_absent', 'customer_rejected', 'address_issue', 'business_closed', 'damaged_goods', 'other'])], 'notes' => ['nullable', 'string', 'max:2000'],
            'collected' => ['nullable', 'boolean'], 'amount' => ['nullable', 'numeric', 'min:0.01'], 'payment_method' => ['nullable', Rule::in(['cash', 'card', 'transfer', 'check'])],
            'reference' => ['nullable', 'string', 'max:255'], 'collected_by' => ['nullable', 'integer', Rule::exists('users', 'id')], 'collected_at' => ['nullable', 'date'],
            'override_reason' => ['nullable', 'string', 'max:2000'], 'receive_cash_in_current_session' => ['nullable', 'boolean'],
        ]);
        $reconciliations->reconcileItem($batch, $entry, $data, $request->user());

        return back()->with('success', $data['delivery_status'] === 'not_delivered'
            ? 'Operación anulada y productos devueltos al inventario.'
            : 'Entrega conciliada.');
    }

    public function correct(Request $request, RouteExternalDeliveryReconciliationItem $item, RouteExternalDeliveryReconciliationCorrectionService $corrections): RedirectResponse
    {
        abort_unless((int) $item->business_id === currentBusinessId(), 403);
        abort_unless((int) BranchInventory::activeBranch((int) $item->business_id)->id === (int) $item->branch_id, 403);
        $data = $request->validate([
            'delivery_status' => ['required', Rule::in(['delivered', 'not_delivered'])],
            'not_delivered_reason' => ['nullable', Rule::in(['customer_absent', 'customer_rejected', 'address_issue', 'business_closed', 'damaged_goods', 'other'])],
            'notes' => ['nullable', 'string', 'max:2000'], 'correction_reason' => ['required', 'string', 'max:2000'],
        ]);
        $corrections->correctDeliveryResult($item, $data, $request->user());

        return back()->with('success', 'Corrección de entrega registrada.');
    }
}
