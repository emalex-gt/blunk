<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use App\Models\RouteBranchCollectionSetting;
use App\Services\Routes\RouteBranchCollectionSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TenantBranchRouteSettingsController extends Controller
{
    public function index(Business $business, Branch $branch, RouteBranchCollectionSettingsService $settings): Response
    {
        $this->ensureBranchBelongsToBusiness($business, $branch);
        $policy = $settings->forBranch((int) $business->id, (int) $branch->id);

        return Inertia::render('SuperAdmin/Tenants/BranchRouteSettings', [
            'tenant' => $business->only('id', 'name'),
            'branch' => $branch->only('id', 'name'),
            'policy' => $this->policyPayload($policy),
            'payment_methods' => [
                ['value' => RouteBranchCollectionSettingsService::PAYMENT_METHOD_CASH, 'label' => 'Efectivo'],
                ['value' => RouteBranchCollectionSettingsService::PAYMENT_METHOD_CARD, 'label' => 'Tarjeta'],
                ['value' => RouteBranchCollectionSettingsService::PAYMENT_METHOD_TRANSFER, 'label' => 'Transferencia'],
                ['value' => RouteBranchCollectionSettingsService::PAYMENT_METHOD_CHECK, 'label' => 'Cheque'],
            ],
        ]);
    }

    public function update(Request $request, Business $business, Branch $branch, RouteBranchCollectionSettingsService $settings): RedirectResponse
    {
        $this->ensureBranchBelongsToBusiness($business, $branch);
        $data = $request->validate([
            'collection_workflow_mode' => ['required', 'string', Rule::in(RouteBranchCollectionSettingsService::WORKFLOWS)],
            'allowed_payment_methods' => ['required', 'array', 'min:1'],
            'allowed_payment_methods.*' => ['string', 'distinct', Rule::in(RouteBranchCollectionSettingsService::PAYMENT_METHODS)],
            'primary_payment_method' => ['required', 'string', Rule::in(RouteBranchCollectionSettingsService::PAYMENT_METHODS)],
        ]);

        $settings->save((int) $business->id, $branch, $data);

        return redirect()
            ->route('super-admin.tenants.branches.route-settings.index', [$business, $branch])
            ->with('success', 'Configuración de rutas guardada.');
    }

    private function ensureBranchBelongsToBusiness(Business $business, Branch $branch): void
    {
        abort_unless((int) $branch->business_id === (int) $business->id, 404);
    }

    /** @return array{collection_workflow_mode:string,allowed_payment_methods:array<int,string>,primary_payment_method:string}|null */
    private function policyPayload(?RouteBranchCollectionSetting $policy): ?array
    {
        if (! $policy) {
            return null;
        }

        return [
            'collection_workflow_mode' => $policy->collection_workflow_mode,
            'allowed_payment_methods' => $policy->allowed_payment_methods,
            'primary_payment_method' => $policy->primary_payment_method,
        ];
    }
}
