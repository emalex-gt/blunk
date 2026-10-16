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
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\DashboardWidgetDefinition;
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
                ->where('dashboard.widgets', fn ($widgets) => collect($widgets)->pluck('key')->all() === [
                    'sales.today',
                    'sales.this_month',
                    'sales.top_product',
                ])
                ->missing('dashboard.widgets.3'));
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
                ->where('dashboard.widgets.3.key', 'sales.estimated_margin')
                ->where('dashboard.widgets.3.data.amount', '60.00'));
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
                ->where('dashboard.widgets.3.key', 'sales.estimated_margin')
                ->where('dashboard.widgets.3.data.amount', '75.00'));
    }

    public function test_cash_register_totals_require_cash_register_view_permission(): void
    {
        [$business, $user] = $this->tenantUser('cashier', ['pos', 'cash_register']);
        Permissions::assignDirectPermissions($user, [Permissions::CASH_REGISTER_VIEW]);
        $this->saleWithProfit($business, $user, total: 100, cost: 40, profit: 60);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('dashboard.widgets.2.key', 'cash.current')
                ->where('dashboard.widgets.2.data.status', 'closed')
                ->missing('dashboard.widgets.4'));
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

    public function test_dashboard_source_renders_capability_filtered_widget_collection(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));

        $this->assertStringContainsString('dashboard.widgets.map', $source);
        $this->assertStringContainsString('data-dashboard-widget', $source);
        $this->assertStringContainsString('widget.title', $source);
        $this->assertStringContainsString('data.expected_cash', $source);
    }

    public function test_credit_sales_effectively_disabled_hides_accounts_action_and_widget(): void
    {
        [$business, $user] = $this->tenantUser('owner', ['pos', 'reports', 'credits']);
        Permissions::assignDirectPermissions($user, [Permissions::CREDITS_ACCOUNTS_VIEW]);

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.actions', fn ($actions) => ! collect($actions)->pluck('key')->contains('credits.accounts'))
            ->where('dashboard.widgets', fn ($widgets) => ! collect($widgets)->pluck('key')->contains('credits.receivable')));

        $business->tenantSetting->update(['enable_credit_reservations' => true]);
        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.actions', fn ($actions) => ! collect($actions)->pluck('key')->contains('credits.accounts'))
            ->where('dashboard.widgets', fn ($widgets) => ! collect($widgets)->pluck('key')->contains('credits.receivable')));
    }

    public function test_credit_sales_effectively_enabled_exposes_accounts_action_and_widget(): void
    {
        [$business, $user] = $this->tenantUser('owner', ['pos', 'reports', 'credits']);
        $business->tenantSetting->update(['enable_credit_sales' => true]);
        Permissions::assignDirectPermissions($user, [Permissions::CREDITS_ACCOUNTS_VIEW]);

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.actions', fn ($actions) => collect($actions)->pluck('key')->contains('credits.accounts'))
            ->where('dashboard.widgets', fn ($widgets) => collect($widgets)->pluck('key')->contains('credits.receivable')));
    }

    public function test_hidden_credit_sales_widget_never_executes_its_metric_query(): void
    {
        [$business, $user] = $this->tenantUser('owner', ['pos', 'reports', 'credits']);
        Permissions::assignDirectPermissions($user, [Permissions::CREDITS_ACCOUNTS_VIEW]);
        $query = new class implements DashboardMetricQuery {
            public function resolve(DashboardContext $context, DashboardPeriod $period): array
            {
                throw new \LogicException('The hidden credit widget must not execute.');
            }
        };
        $registry = ['credits.receivable' => new DashboardWidgetDefinition(
            key: 'credits.receivable', title: 'CxC', type: 'money', period: DashboardPeriod::CURRENT,
            query: $query, requiredModules: ['credits'], requiredPermissions: [Permissions::CREDITS_ACCOUNTS_VIEW], requiredFeatures: ['credit_sales'],
        )];

        $dashboard = (new DashboardService($registry))->resolve(DashboardContext::forUser($business, $user), 'executive');
        $this->assertSame([], $dashboard['widgets']);
    }

    private function tenantUser(string $role, array $modules = ['pos']): array
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
