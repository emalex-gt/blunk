<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Models\MaintenanceAnnouncement;
use App\Services\BranchAccessService;
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

        if (! $user) {
            return array_merge(parent::share($request), [
                'auth' => [
                    'user' => null,
                    'permissions' => [],
                ],
                'current_business_id' => null,
                'current_business' => null,
                'available_businesses' => null,
                'business' => null,
                'tenant_settings' => null,
                'fel_settings' => null,
                'currency_format' => Currency::forCountry('GT'),
                'subscription_status' => null,
                'enabled_modules' => [],
                'features' => $this->guestFeatures(),
                'branches_enabled' => false,
                'branch_can_switch' => false,
                'active_branch' => null,
                'branches' => [],
                'navigation_brand' => [
                    'name' => null,
                    'logo_url' => null,
                    'subtitle' => 'Blunk v2.0',
                ],
                'use_product_images' => true,
                'maintenance_announcement' => null,
                'flash' => [
                    'success' => fn () => $request->session()->get('success'),
                    'receipt_sale_id' => fn () => $request->session()->get('receipt_sale_id'),
                    'fel_print_sale_id' => fn () => $request->session()->get('fel_print_sale_id'),
                    'fel_print_url' => fn () => $request->session()->get('fel_print_url'),
                    'fel_success_message' => fn () => $request->session()->get('fel_success_message'),
                    'warning' => fn () => $request->session()->get('warning'),
                    'fiscal_intent_id' => fn () => $request->session()->get('fiscal_intent_id'),
                    'provisional_receipt_url' => fn () => $request->session()->get('provisional_receipt_url'),
                    'credit_print_url' => fn () => $request->session()->get('credit_print_url'),
                    'credit_receipt_id' => fn () => $request->session()->get('credit_receipt_id'),
                    'credit_payment_print_url' => fn () => $request->session()->get('credit_payment_print_url'),
                    'cash_closing_print_id' => fn () => $request->session()->get('cash_closing_print_id'),
                    'global_preparation_result' => fn () => $request->session()->get('global_preparation_result'),
                    'global_sales_result' => fn () => $request->session()->get('global_sales_result'),
                ],
            ]);
        }

        $businessId = $user ? currentBusinessId() : null;

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
                    ->select('id', 'name', 'country', 'is_active', 'logo_url')
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
                    ->select('id', 'name', 'country', 'is_active', 'logo_url')
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

            'subscription_status' => fn () => $user && $businessId
                ? Business::query()->with('latestSubscription')->find($businessId)?->latestSubscription?->status
                : null,
            'enabled_modules' => fn () => $user && $businessId ? enabled_modules($businessId) : [],
            'features' => fn () => $user && $businessId ? $this->tenantFeatures($businessId) : $this->guestFeatures(),
            'branches_enabled' => fn () => $user && $businessId ? BranchInventory::branchesEnabled($businessId) : false,
            'branch_can_switch' => fn () => $user && $businessId ? BranchInventory::canSwitchBranches($user) : false,
            'active_branch' => function () use ($businessId, $user) {
                if (! $user || ! $businessId || ! BranchInventory::branchesEnabled($businessId)) {
                    return null;
                }

                $branch = BranchInventory::activeBranch($businessId);

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'logo_url' => $branch->logo_url,
                ];
            },
            'branches' => function () use ($businessId, $user) {
                if (! $user || ! $businessId || ! BranchInventory::branchesEnabled($businessId)) {
                    return [];
                }

                return app(BranchAccessService::class)
                    ->authorizedBranches($user, $businessId)
                    ->map(fn ($branch) => [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'code' => $branch->code,
                    ])
                    ->values();
            },
            'navigation_brand' => function () use ($businessId, $user) {
                if (! $user || ! $businessId) {
                    return [
                        'business_name' => null,
                        'branch_name' => null,
                        'logo_url' => null,
                        'subtitle' => 'Blunk v2.0',
                    ];
                }

                $business = Business::query()
                    ->select('id', 'name', 'logo_url')
                    ->find($businessId);

                if (! $business) {
                    return [
                        'business_name' => null,
                        'branch_name' => null,
                        'logo_url' => null,
                        'subtitle' => 'Blunk v2.0',
                    ];
                }

                $tenantLogo = $business->logo_url
                    ?: TenantSetting::query()
                        ->where('business_id', $businessId)
                        ->value('company_logo_url');

                $branch = BranchInventory::branchesEnabled($businessId)
                    ? BranchInventory::activeBranch($businessId)
                    : null;

                return [
                    'business_name' => $business->name,
                    'branch_name' => $branch?->name,
                    'logo_url' => $branch?->logo_url
                                    ?: $tenantLogo
                                    ?: asset('images/blunk-logo.png'),
                    'subtitle' => 'Blunk v2.0',
                ];
            },
            'use_product_images' => fn () => $user && $businessId ? tenantSetting('use_product_images', true) : true,
            'maintenance_announcement' => function () use ($businessId) {
                $announcement = MaintenanceAnnouncement::query()
                    ->visible()
                    ->latest('starts_at')
                    ->first();

                if (! $announcement) {
                    return null;
                }

                return $announcement->publicPayload($businessId ? tenantTimezone($businessId) : 'UTC');
            },
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'receipt_sale_id' => fn () => $request->session()->get('receipt_sale_id'),
                'fel_print_sale_id' => fn () => $request->session()->get('fel_print_sale_id'),
                'fel_print_url' => fn () => $request->session()->get('fel_print_url'),
                'fel_success_message' => fn () => $request->session()->get('fel_success_message'),
                'warning' => fn () => $request->session()->get('warning'),
                'fiscal_intent_id' => fn () => $request->session()->get('fiscal_intent_id'),
                'provisional_receipt_url' => fn () => $request->session()->get('provisional_receipt_url'),
                'credit_print_url' => fn () => $request->session()->get('credit_print_url'),
                'credit_receipt_id' => fn () => $request->session()->get('credit_receipt_id'),
                'credit_payment_print_url' => fn () => $request->session()->get('credit_payment_print_url'),
                'cash_closing_print_id' => fn () => $request->session()->get('cash_closing_print_id'),
                'global_preparation_result' => fn () => $request->session()->get('global_preparation_result'),
                'global_sales_result' => fn () => $request->session()->get('global_sales_result'),
            ],
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function guestFeatures(): array
    {
        return [
            'enable_credit_sales' => false,
            'enable_credit_reservations' => false,
            'reserve_stock_on_credit_reservations' => false,
            'fel_enabled' => false,
            'routes_enabled' => false,
            'reports_enabled' => false,
            'inventory_enabled' => false,
            'branches_enabled' => false,
            'purchases_enabled' => false,
            'cash_register_enabled' => false,
            'pos_enabled' => false,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function tenantFeatures(int $businessId): array
    {
        $modules = enabled_modules($businessId);
        $hasModule = fn (string $module): bool => in_array($module, $modules, true);
        $settings = TenantSetting::query()
            ->where('business_id', $businessId)
            ->first();
        $business = Business::query()
            ->select('id', 'country')
            ->find($businessId);
        $felSettings = $business?->country === 'GT'
            ? TenantFelSetting::query()->where('business_id', $businessId)->first()
            : null;

        $creditReservationsEnabled = $hasModule('credits') && (bool) ($settings?->enable_credit_reservations);

        return [
            'enable_credit_sales' => $hasModule('credits') && (bool) ($settings?->enable_credit_sales),
            'enable_credit_reservations' => $creditReservationsEnabled,
            'reserve_stock_on_credit_reservations' => $creditReservationsEnabled && (bool) ($settings?->reserve_stock_on_credit_reservations ?? true),
            'fel_enabled' => $hasModule('fel_gt') && (bool) ($felSettings?->enabled) && (bool) ($felSettings?->isConfigured()),
            'routes_enabled' => $hasModule('routes'),
            'reports_enabled' => $hasModule('reports'),
            'inventory_enabled' => $hasModule('inventory'),
            'branches_enabled' => $hasModule('branches'),
            'purchases_enabled' => $hasModule('purchases'),
            'cash_register_enabled' => $hasModule('cash_register'),
            'pos_enabled' => $hasModule('pos'),
        ];
    }
}
