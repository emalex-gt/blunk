<?php

namespace App\Http\Controllers;

use App\Services\Routes\RouteBranchCollectionSettingsService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RouteBranchCollectionSettingsController extends Controller
{
    public function index(Request $request, RouteBranchCollectionSettingsService $settings): Response
    {
        [$businessId, $branch] = $this->scope($request);
        $policy = $settings->forBranch($businessId, (int) $branch->id);

        return Inertia::render('Routes/BranchCollectionSettings/Index', [
            'branch' => $branch->only('id', 'name'),
            'policy' => $policy ? [
                'collection_workflow_mode' => $policy->collection_workflow_mode,
                'allowed_payment_methods' => $policy->allowed_payment_methods,
                'primary_payment_method' => $policy->primary_payment_method,
            ] : null,
            'payment_methods' => [
                ['value' => RouteBranchCollectionSettingsService::PAYMENT_METHOD_CASH, 'label' => 'Efectivo'],
                ['value' => RouteBranchCollectionSettingsService::PAYMENT_METHOD_CARD, 'label' => 'Tarjeta'],
                ['value' => RouteBranchCollectionSettingsService::PAYMENT_METHOD_TRANSFER, 'label' => 'Transferencia'],
                ['value' => RouteBranchCollectionSettingsService::PAYMENT_METHOD_CHECK, 'label' => 'Cheque'],
            ],
        ]);
    }

    public function update(Request $request, RouteBranchCollectionSettingsService $settings): RedirectResponse
    {
        [$businessId, $branch] = $this->scope($request);
        $data = $request->validate([
            'collection_workflow_mode' => ['required', 'string', Rule::in(RouteBranchCollectionSettingsService::WORKFLOWS)],
            'allowed_payment_methods' => ['required', 'array', 'min:1'],
            'allowed_payment_methods.*' => ['string', 'distinct', Rule::in(RouteBranchCollectionSettingsService::PAYMENT_METHODS)],
            'primary_payment_method' => ['required', 'string', Rule::in(RouteBranchCollectionSettingsService::PAYMENT_METHODS)],
        ]);

        $settings->save($businessId, $branch, $data);

        return redirect()->route('routes.branch-collection-settings.index')
            ->with('success', 'Configuración de rutas guardada.');
    }

    /** @return array{int, \App\Models\Branch} */
    private function scope(Request $request): array
    {
        $businessId = (int) currentBusinessId();
        $actor = $request->user();
        abort_unless(
            $businessId > 0
            && $actor
            && $actor->is_active
            && (int) $actor->business_id === $businessId
            && Permissions::userHas($actor, Permissions::ROUTES_PRE_SALES_ADMIN_VIEW),
            403,
        );

        $branch = BranchInventory::activeBranch($businessId);
        abort_unless((int) $actor->current_branch_id === (int) $branch->id, 403);

        return [$businessId, $branch];
    }
}
