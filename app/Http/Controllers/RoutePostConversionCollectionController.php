<?php

namespace App\Http\Controllers;

use App\Models\RouteDeliveryBatchPreSale;
use App\Models\RoutePostConversionCollection;
use App\Models\User;
use App\Services\Routes\RoutePostConversionCollectionReader;
use App\Services\Routes\RoutePostConversionCollectionReversalService;
use App\Services\Routes\RoutePostConversionCollectionService;
use App\Services\Routes\RouteBranchCollectionSettingsService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoutePostConversionCollectionController extends Controller
{
    public function index(Request $request, RoutePostConversionCollectionReader $reader): Response
    {
        $actor = $this->actor($request, Permissions::ROUTES_POST_CONVERSION_COLLECTIONS_COLLECT);
        return Inertia::render('Routes/Mobile/PostConversionCollections/Index', [
            'collections' => $reader->pendingForSeller($actor),
            'payment_policy' => $reader->displayPolicy((int) $actor->business_id, (int) $actor->current_branch_id),
        ]);
    }

    public function store(Request $request, RoutePostConversionCollectionService $collections): RedirectResponse
    {
        $actor = $this->actor($request, Permissions::ROUTES_POST_CONVERSION_COLLECTIONS_COLLECT);
        $isAdmin = Permissions::userHas($actor, Permissions::ROUTES_PRE_SALES_ADMIN_VIEW);
        $rules = [
            'route_delivery_batch_pre_sale_id' => ['required', 'integer', Rule::exists('route_delivery_batch_pre_sales', 'id')],
            'payment_method' => ['required', Rule::in(RouteBranchCollectionSettingsService::PAYMENT_METHODS)],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'reference' => ['nullable', 'string', 'max:255'],
            'details' => ['nullable', 'array'],
        ];
        if ($isAdmin) {
            $rules += ['collected_by' => ['nullable', 'integer', Rule::exists('users', 'id')], 'override_reason' => ['nullable', 'string', 'max:2000']];
        } else {
            $rules += ['amount' => ['prohibited'], 'business_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'sale_id' => ['prohibited'], 'collected_by' => ['prohibited'], 'override_reason' => ['prohibited']];
        }
        $data = $request->validate($rules);
        $entry = RouteDeliveryBatchPreSale::query()->with('batch.workDay', 'preSale')->findOrFail($data['route_delivery_batch_pre_sale_id']);
        $this->authorizeEntry($entry, $actor, $isAdmin);

        if ($isAdmin && isset($data['collected_by']) && (int) $data['collected_by'] !== (int) $actor->id) {
            abort_unless(Permissions::userHas($actor, Permissions::ROUTES_COLLECTIONS_OVERRIDE), 403);
            validator($data, ['override_reason' => ['required', 'string', 'max:2000']])->validate();
        } elseif ($isAdmin) {
            $data['collected_by'] = $actor->id;
            $data['override_reason'] = null;
        }

        $collections->collect($entry, $data, $actor, $data['idempotency_key']);
        return back()->with('success', 'Cobro registrado correctamente.');
    }

    public function reverse(Request $request, RoutePostConversionCollection $collection, RoutePostConversionCollectionReversalService $reversals): RedirectResponse
    {
        $actor = $this->actor($request, Permissions::ROUTES_POST_CONVERSION_COLLECTIONS_REVERSE);
        abort_unless((int) $collection->business_id === (int) $actor->business_id && (int) $collection->branch_id === (int) $actor->current_branch_id, 403);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'reason_code' => ['required', Rule::in(['payment_recorded_by_mistake', 'wrong_customer', 'duplicate_collection', 'wrong_amount', 'other'])],
            'explanation' => ['required', 'string', 'max:4000'],
            'confirmed' => ['accepted'],
            'confirm_cash_adjustment' => ['nullable', 'boolean'],
        ]);
        $reversals->reverse($collection, $data, $actor, $data['idempotency_key']);
        return back()->with('success', 'Cobro revertido correctamente.');
    }

    private function actor(Request $request, string $permission): User
    {
        $actor = $request->user();
        abort_unless($actor && $actor->is_active && (int) $actor->business_id === currentBusinessId() && $actor->current_branch_id && Permissions::userHas($actor, $permission), 403);
        return $actor;
    }

    private function authorizeEntry(RouteDeliveryBatchPreSale $entry, User $actor, bool $isAdmin): void
    {
        $batch = $entry->batch;
        abort_unless($batch && (int) $batch->business_id === (int) $actor->business_id && (int) $batch->branch_id === (int) $actor->current_branch_id, 403);
        if (! $isAdmin) {
            abort_unless((int) $entry->preSale?->seller_id === (int) $actor->id && (int) $batch->workDay?->seller_id === (int) $actor->id, 403);
        }
    }
}
