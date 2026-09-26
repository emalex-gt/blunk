<?php

namespace App\Http\Controllers;

use App\Models\RouteDeliveryStop;
use App\Models\RouteExternalDeliveryReconciliationItem;
use App\Services\Routes\RouteOperationReturnService;
use App\Support\BranchInventory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RouteOperationReturnController extends Controller
{
    public function external(Request $request, RouteExternalDeliveryReconciliationItem $item, RouteOperationReturnService $returns): RedirectResponse
    {
        $this->assertScope($item->business_id, $item->branch_id);
        $returns->completeExternal($item, $this->validated($request), $request->user());

        return back()->with('success', 'Devolución registrada. La entrega original se conserva y la venta quedó anulada.');
    }

    public function inApp(Request $request, RouteDeliveryStop $stop, RouteOperationReturnService $returns): RedirectResponse
    {
        $this->assertScope($stop->business_id, $stop->branch_id);
        $returns->completeStop($stop, $this->validated($request), $request->user());

        return back()->with('success', 'Devolución registrada. La entrega original se conserva y la venta quedó anulada.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'reason' => ['required', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'goods_received' => ['required', 'accepted'],
            'refund_cash_confirmed' => ['sometimes', 'accepted'],
        ]);
    }

    private function assertScope(int $businessId, int $branchId): void
    {
        abort_unless($businessId === (int) currentBusinessId(), 403);
        abort_unless((int) BranchInventory::activeBranch($businessId)->id === $branchId, 403);
    }
}
