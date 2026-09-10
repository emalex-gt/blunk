<?php

namespace App\Http\Controllers;

use App\Models\RouteDeliveryCollection;
use App\Services\Routes\RouteDeliveryCollectionReversalService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RouteDeliveryCollectionReversalController extends Controller
{
    public function store(Request $request, RouteDeliveryCollection $collection, RouteDeliveryCollectionReversalService $reversals): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->is_active && (int) $actor->business_id === currentBusinessId() && (int) $actor->current_branch_id === (int) $collection->branch_id && (int) $collection->business_id === currentBusinessId() && Permissions::userHas($actor, Permissions::ROUTES_DELIVERY_COLLECTIONS_REVERSE), 403);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'reason_code' => ['required', Rule::in(['payment_recorded_by_mistake', 'wrong_customer', 'duplicate_collection', 'wrong_amount', 'other'])],
            'explanation' => ['required', 'string', 'max:4000'],
            'confirmed' => ['accepted'],
            'confirm_cash_adjustment' => ['nullable', 'boolean'],
        ]);
        $reversals->reverse($collection, $data, $actor, $data['idempotency_key']);
        return back()->with('success', 'Cobro reversado. La reversa no representa una devolución al cliente.');
    }
}
