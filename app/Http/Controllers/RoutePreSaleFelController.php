<?php

namespace App\Http\Controllers;

use App\Models\PreSale;
use App\Services\Routes\RoutePreSaleFelService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RoutePreSaleFelController extends Controller
{
    public function certify(Request $request, PreSale $preSale, RoutePreSaleFelService $fel): RedirectResponse
    {
        abort_unless((int) $preSale->business_id === currentBusinessId(), 403);
        abort_unless(Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_ADMIN_VIEW), 403);
        abort_unless(Permissions::userHas($request->user(), Permissions::FEL_CERTIFY), 403);
        abort_unless(module_enabled('routes', $preSale->business_id) && module_enabled('fel_gt', $preSale->business_id), 403);
        abort_unless((int) BranchInventory::activeBranch((int) $preSale->business_id)->id === (int) $preSale->branch_id, 403);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);

        $result = $fel->certify($preSale, $request->user(), $data['idempotency_key']);

        return redirect()
            ->route('routes.pre-sales.show', $preSale)
            ->with('success', $result->replayed ? 'La solicitud FEL ya había sido procesada.' : 'Factura FEL certificada correctamente.');
    }
}
