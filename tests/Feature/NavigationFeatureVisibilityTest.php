<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NavigationFeatureVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_shared_props_include_disabled_feature_flags(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('features.enable_credit_sales', false)
                ->where('features.enable_credit_reservations', false)
                ->where('features.reserve_stock_on_credit_reservations', false)
                ->where('features.fel_enabled', false));
    }

    public function test_credit_sales_disabled_hides_ar_feature_flags_and_direct_route_is_blocked(): void
    {
        [$user] = $this->tenantUser([
            'credits',
            'pos',
        ], [
            'enable_credit_sales' => false,
            'enable_credit_reservations' => true,
            'reserve_stock_on_credit_reservations' => true,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('features.enable_credit_sales', false)
                ->where('features.enable_credit_reservations', true)
                ->where('features.reserve_stock_on_credit_reservations', true));

        $this->actingAs($user)
            ->get(route('credits.accounts.index'))
            ->assertForbidden();
    }

    public function test_credit_sales_enabled_exposes_ar_feature_flags(): void
    {
        [$user] = $this->tenantUser(['credits'], [
            'enable_credit_sales' => true,
            'enable_credit_reservations' => false,
            'reserve_stock_on_credit_reservations' => true,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('features.enable_credit_sales', true)
                ->where('features.enable_credit_reservations', false)
                ->where('features.reserve_stock_on_credit_reservations', false));
    }

    public function test_reserve_stock_setting_alone_does_not_enable_reservation_feature(): void
    {
        [$user] = $this->tenantUser(['credits'], [
            'enable_credit_sales' => false,
            'enable_credit_reservations' => false,
            'reserve_stock_on_credit_reservations' => true,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('features.enable_credit_sales', false)
                ->where('features.enable_credit_reservations', false)
                ->where('features.reserve_stock_on_credit_reservations', false));

        $this->actingAs($user)
            ->post(route('credits.receipts.store'), [])
            ->assertSessionHasErrors('document_type');
    }

    public function test_tenant_navigation_source_uses_feature_flags_and_excludes_fel_reconciliation(): void
    {
        $source = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.tsx'));

        $this->assertStringContainsString("creditSalesEnabled && can('credits.accounts.view')", $source);
        $this->assertStringContainsString("creditSalesEnabled && (can('credits.payments.view') || can('credits.payments.create'))", $source);
        $this->assertStringContainsString('creditReservationsEnabled && canViewCredits', $source);
        $this->assertStringNotContainsString("route('fel.reconciliation.index')", $source);
    }

    private function tenantUser(array $modules, array $settings): array
    {
        Permissions::syncDefaults();

        $business = Business::query()->create([
            'name' => 'Navigation Tenant',
            'slug' => 'navigation-tenant-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);

        TenantSetting::query()->create(array_merge([
            'business_id' => $business->id,
            'use_product_images' => true,
            'max_users' => 10,
            'use_branches' => false,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_manual_price' => false,
            'remember_last_customer_product_price' => false,
            'enable_credit_sales' => false,
            'enable_credit_reservations' => false,
            'reserve_stock_on_credit_reservations' => true,
            'allow_negative_stock' => false,
            'allow_receipts' => true,
            'allow_invoices' => false,
        ], $settings));

        foreach ($modules as $module) {
            TenantModule::query()->create([
                'business_id' => $business->id,
                'module' => $module,
                'is_enabled' => true,
                'enabled_at' => now(),
            ]);
        }

        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        Permissions::assignRole($user, 'owner');

        return [$user, $business];
    }
}
