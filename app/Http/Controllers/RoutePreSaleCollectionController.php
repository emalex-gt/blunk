<?php

namespace App\Http\Controllers;

use App\Models\PreSale;
use App\Models\User;
use App\Services\Routes\RoutePreSaleCollectionService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoutePreSaleCollectionController extends Controller
{
    public function store(Request $request, PreSale $preSale, RoutePreSaleCollectionService $collections): RedirectResponse
    {
        abort_unless((int) $preSale->business_id === currentBusinessId(), 403);
        abort_unless((int) BranchInventory::activeBranch((int) $preSale->business_id)->id === (int) $preSale->branch_id, 403);

        $actor = $request->user();
        $isOverride = (int) $preSale->seller_id !== (int) $actor->id;
        abort_unless(! $isOverride || Permissions::userHas($actor, Permissions::ROUTES_COLLECTIONS_OVERRIDE), 403);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'transfer', 'check'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'collected_by' => [$isOverride ? 'required' : 'nullable', 'integer', Rule::exists('users', 'id')],
            'collected_at' => [$isOverride ? 'required' : 'nullable', 'date'],
            'override_reason' => [$isOverride ? 'required' : 'nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['collected_by'])) {
            User::query()->where('business_id', $preSale->business_id)->findOrFail($data['collected_by']);
        }
        $collections->capture($preSale, $data, $actor);

        return back()->with('success', 'Cobro registrado.');
    }
}
