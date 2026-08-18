<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Permission;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardFinancialVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_cashier_dashboard_does_not_receive_estimated_margin_without_profit_permission(): void
    {
        [$business, $user] = $this->tenantUser('cashier');
        $this->saleWithProfit($business, $user, total: 100, cost: 40, profit: 60);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.sales_total', 100)
                ->where('stats.sales_count', 1)
                ->missing('stats.cash_register_expected')
                ->missing('stats.cash_register_status')
                ->missing('stats.estimated_margin'));
    }

    public function test_user_with_profit_permission_receives_estimated_margin(): void
    {
        [$business, $user] = $this->tenantUser('cashier');
        Permissions::assignDirectPermissions($user, [Permissions::REPORTS_PROFIT_VIEW]);
        $this->saleWithProfit($business, $user, total: 100, cost: 40, profit: 60);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.estimated_margin', 60));
    }

    public function test_admin_dashboard_receives_estimated_margin_by_default_role_permission(): void
    {
        [$business, $user] = $this->tenantUser('admin');
        $this->saleWithProfit($business, $user, total: 100, cost: 25, profit: 75);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.estimated_margin', 75));
    }

    public function test_cash_register_totals_require_cash_register_view_permission(): void
    {
        [$business, $user] = $this->tenantUser('cashier');
        Permissions::assignDirectPermissions($user, [Permissions::CASH_REGISTER_VIEW]);
        $this->saleWithProfit($business, $user, total: 100, cost: 40, profit: 60);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.cash_register_status', 'closed')
                ->where('stats.cash_register_expected', null)
                ->missing('stats.estimated_margin'));
    }

    public function test_profit_permission_has_financial_visibility_metadata(): void
    {
        Permissions::syncDefaults();

        $permission = Permission::query()
            ->where('key', Permissions::REPORTS_PROFIT_VIEW)
            ->firstOrFail();

        $this->assertSame('Ver márgenes y rentabilidad', $permission->name);
        $this->assertSame('Permite ver costos, márgenes, utilidad estimada y rentabilidad.', $permission->description);
    }

    public function test_cashier_role_does_not_include_profit_permission_by_default(): void
    {
        [, $user] = $this->tenantUser('cashier');

        $this->assertFalse($user->hasPermission(Permissions::REPORTS_PROFIT_VIEW));
    }

    public function test_dashboard_source_renders_margin_only_when_prop_exists(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));

        $this->assertStringContainsString("const hasEstimatedMargin = typeof stats.estimated_margin === 'number';", $source);
        $this->assertStringContainsString('{hasEstimatedMargin && (', $source);
        $this->assertStringContainsString('Margen estimado de hoy', $source);
    }

    private function tenantUser(string $role): array
    {
        $business = Business::query()->create([
            'name' => 'Dashboard Tenant',
            'slug' => 'dashboard-tenant-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);

        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_product_images' => true,
            'use_branches' => false,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);

        TenantModule::query()->create([
            'business_id' => $business->id,
            'module' => 'pos',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => $role,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        Permissions::assignRole($user, $role);

        return [$business, $user];
    }

    private function saleWithProfit(Business $business, User $user, float $total, float $cost, float $profit): Sale
    {
        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'business_number' => 1,
            'customer_name' => 'Cliente',
            'total' => $total,
            'payment_method' => 'cash',
            'document_type' => 'receipt',
            'status' => 'completed',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        SaleItem::query()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'product_name' => 'Producto',
            'quantity' => 1,
            'unit_price' => $total,
            'unit_cost' => $cost,
            'total_cost' => $cost,
            'profit_amount' => $profit,
            'total' => $total,
            'total_before_discount' => $total,
            'total_after_discount' => $total,
        ]);

        return $sale;
    }
}
