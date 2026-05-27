<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Support\BranchInventory;
use App\Support\Permissions;
use App\Models\TenantFelSetting;
use App\Models\TenantSetting;
use Illuminate\Http\Request;
use App\Support\Currency;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $businessId = currentBusinessId();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user
                    ? $user->only('id', 'name', 'email', 'business_id', 'role', 'is_super_admin', 'is_active')
                    : null,
                'permissions' => fn () => $user?->permissions() ?? [],
            ],

            'current_business_id' => fn () => $businessId,

            'current_business' => function () use ($businessId) {
                if (! $businessId) {
                    return null;
                }

                return Business::query()
                    ->select('id', 'name', 'country', 'is_active')
                    ->find($businessId);
            },

            'available_businesses' => function () use ($user) {
                if ($user?->id !== 1) {
                    return null;
                }

                return Business::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name']);
            },

            'business' => function () use ($businessId) {
                if (! $businessId) {
                    return null;
                }

                return Business::query()
                    ->select('id', 'name', 'country', 'is_active')
                    ->find($businessId);
            },

            'tenant_settings' => function () use ($businessId) {
                if (! $businessId) {
                    return null;
                }

                return TenantSetting::query()
                    ->where('business_id', $businessId)
                    ->first();
            },

            'fel_settings' => function () use ($businessId) {
                if (! $businessId) {
                    return null;
                }

                $business = Business::query()->select('id', 'country')->find($businessId);

                if ($business?->country !== 'GT') {
                    return null;
                }

                $settings = TenantFelSetting::query()
                    ->where('business_id', $businessId)
                    ->first();
                $moduleEnabled = module_enabled('fel_gt', $businessId);
                $felEnabled = (bool) ($settings?->enabled);
                $felConfigured = (bool) ($settings?->isConfigured());

                return [
                    'provider' => $settings?->provider ?? 'digifact',
                    'environment' => $settings?->environment ?? 'test',
                    'module_enabled' => $moduleEnabled,
                    'enabled' => $felEnabled,
                    'configured' => $felConfigured,
                    'available' => $moduleEnabled && $felConfigured,
                    'missing_fields' => $settings?->missingConfigurationFields() ?? [],
                ];
            },

            'currency_format' => function () use ($businessId) {
                if (! $businessId) {
                    return Currency::forCountry('GT');
                }

                $country = Business::query()
                    ->whereKey($businessId)
                    ->value('country') ?: 'GT';

                return Currency::forCountry($country);
            },

            'subscription_status' => fn () => $businessId
                ? Business::query()->with('latestSubscription')->find($businessId)?->latestSubscription?->status
                : null,
            'enabled_modules' => fn () => $businessId ? enabled_modules($businessId) : [],
            'branches_enabled' => fn () => $businessId ? BranchInventory::branchesEnabled($businessId) : false,
            'active_branch' => function () use ($businessId) {
                if (! $businessId || ! BranchInventory::branchesEnabled($businessId)) {
                    return null;
                }

                $branch = BranchInventory::activeBranch($businessId);

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                ];
            },
            'branches' => function () use ($businessId) {
                if (! $businessId || ! BranchInventory::branchesEnabled($businessId)) {
                    return [];
                }

                $user = request()->user();

                if (! Permissions::userHas($user, Permissions::BRANCHES_MANAGE)) {
                    $branch = $user?->currentBranch;

                    $branch = $branch && (int) $branch->business_id === (int) $businessId && $branch->is_active
                        ? $branch
                        : BranchInventory::activeBranch($businessId);

                    return collect([[
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'code' => $branch->code,
                    ]]);
                }

                return BranchInventory::branchOptions($businessId);
            },
            'use_product_images' => fn () => tenantSetting('use_product_images', true),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'receipt_sale_id' => fn () => $request->session()->get('receipt_sale_id'),
                'fel_print_sale_id' => fn () => $request->session()->get('fel_print_sale_id'),
                'fel_print_url' => fn () => $request->session()->get('fel_print_url'),
                'fel_success_message' => fn () => $request->session()->get('fel_success_message'),
                'cash_closing_print_id' => fn () => $request->session()->get('cash_closing_print_id'),
            ],
        ]);
    }
}
