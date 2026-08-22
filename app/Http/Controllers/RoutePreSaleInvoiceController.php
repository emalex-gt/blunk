<?php

namespace App\Http\Controllers;

use App\Models\PreSale;
use App\Models\User;
use App\Services\Routes\RoutePreSaleInvoiceService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RoutePreSaleInvoiceController extends Controller
{
    public function store(Request $request, PreSale $preSale, RoutePreSaleInvoiceService $invoices): RedirectResponse
    {
        abort_unless((int) $preSale->business_id === currentBusinessId(), 403);
        abort_unless(Permissions::userHas($request->user(), Permissions::ROUTES_PRE_SALES_INVOICE), 403);
        abort_unless(module_enabled('routes', $preSale->business_id) && module_enabled('pos', $preSale->business_id), 403);

        $activeBranch = BranchInventory::activeBranch((int) $preSale->business_id);
        abort_unless((int) $activeBranch->id === (int) $preSale->branch_id, 403);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'document_type' => ['required', 'in:receipt,invoice'],
            'payment_condition' => ['required', 'in:paid,credit'],
            'payment_method' => ['nullable', 'required_if:payment_condition,paid', 'in:cash,card,transfer,check'],
            'due_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $invoices->convert($preSale, $data, $request->user());

        return redirect()
            ->route('routes.pre-sales.show', $preSale)
            ->with('success', $result->replayed ? 'La preventa ya había sido facturada.' : 'Preventa facturada correctamente.');
    }
}
