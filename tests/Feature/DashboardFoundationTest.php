<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardMetricQuery;
use App\Services\Dashboard\DashboardPeriod;
use App\Services\Dashboard\DashboardProfiles;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\DashboardWidgetDefinition;
use App\Services\Dashboard\DashboardWidgetRegistry;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::syncDefaults();
        Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', 'America/Guatemala'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_simple_dashboard_exposes_only_authorized_widgets_with_current_and_month_sales(): void
    {
        [$business, $owner, $branch] = $this->tenant('owner');
        $product = $this->product($business, $branch, 'Martillo', 2, 3);
        $this->sale($business, $branch, $owner, $product, 100, 2, '2026-10-07 10:00:00');
        $this->sale($business, $branch, $owner, $product, 50, 1, '2026-10-03 10:00:00');
        $this->sale($business, $branch, $owner, $product, 999, 1, '2026-10-07 10:00:00', 'cancelled');

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('dashboard.profile', 'owner_simple')
                ->where('dashboard.context.timezone', 'America/Guatemala')
                ->where('dashboard.widgets.0.key', 'sales.today')
                ->where('dashboard.widgets.0.data.total', '100.00')
                ->where('dashboard.widgets.0.data.count', 1)
                ->where('dashboard.widgets.0.data.cancelled_count', 1)
                ->where('dashboard.widgets.1.key', 'sales.this_month')
                ->where('dashboard.widgets.1.data.total', '150.00')
                ->where('dashboard.widgets.2.key', 'cash.current')
                ->where('dashboard.widgets.2.data.status', 'closed')
                ->where('dashboard.widgets.3.key', 'stock.alerts')
                ->where('dashboard.widgets.3.data.low_stock_count', 1)
                ->where('dashboard.widgets.4.key', 'sales.top_product')
                ->where('dashboard.widgets.4.data.name', 'Martillo')
                ->where('dashboard.widgets.5.key', 'sales.estimated_margin'));
    }

    public function test_dashboard_is_tenant_and_active_branch_scoped_and_ignores_branch_query_input(): void
    {
        [$business, $owner, $main, $other] = $this->tenant('owner', branches: true);
        $mainProduct = $this->product($business, $main, 'Sucursal principal', 2, 3);
        $otherProduct = $this->product($business, $other, 'Otra sucursal', 0, 3);
        $this->sale($business, $main, $owner, $mainProduct, 100, 2);
        $this->sale($business, $other, $owner, $otherProduct, 900, 9);

        [$otherBusiness, $otherUser, $otherBranch] = $this->tenant('owner');
        $foreignProduct = $this->product($otherBusiness, $otherBranch, 'Otro tenant', 0, 2);
        $this->sale($otherBusiness, $otherBranch, $otherUser, $foreignProduct, 5000, 5);

        $this->actingAs($owner)
            ->get(route('dashboard', ['branch_id' => $other->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.context.branch.id', $main->id)
                ->where('dashboard.widgets.0.data.total', '100.00')
                ->where('dashboard.widgets.3.data.out_of_stock_count', 1)
                ->where('dashboard.widgets.4.data.name', 'Sucursal principal'));
    }

    public function test_widgets_with_missing_modules_or_permissions_are_not_exposed(): void
    {
        [$business, $cashier, $branch] = $this->tenant('cashier', modules: ['pos']);
        $this->product($business, $branch, 'Oculto', 0, 2);

        $this->actingAs($cashier)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.widgets', fn ($widgets) => collect($widgets)->pluck('key')->all() === ['sales.today', 'sales.this_month', 'sales.top_product'])
                ->missing('dashboard.widgets.3'));
    }

    public function test_today_uses_tenant_timezone_boundaries(): void
    {
        [$business, $owner, $branch] = $this->tenant('owner');
        $product = $this->product($business, $branch, 'Zona horaria', 4, 2);
        $this->sale($business, $branch, $owner, $product, 100, 1, '2026-10-07 00:15:00', 'completed', 'America/Guatemala');
        $this->sale($business, $branch, $owner, $product, 200, 1, '2026-10-06 23:45:00', 'completed', 'America/Guatemala');

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.widgets.0.data.total', '100.00')
                ->where('dashboard.widgets.1.data.total', '300.00'));
    }

    public function test_cash_widget_uses_only_the_current_branch_open_session(): void
    {
        [$business, $owner, $main, $other] = $this->tenant('owner', branches: true);
        CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $other->id,
            'opened_by' => $owner->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 900,
            'opened_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.widgets.2.key', 'cash.current')
                ->where('dashboard.widgets.2.data.status', 'closed')
                ->where('dashboard.widgets.2.data.expected_cash', null));

        CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'opened_by' => $owner->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 125,
            'opened_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.widgets.2.data.status', 'open')
                ->where('dashboard.widgets.2.data.expected_cash', '0.00'));
    }

    public function test_hidden_widgets_do_not_execute_their_metric_query(): void
    {
        [$business, $cashier, $branch] = $this->tenant('cashier', modules: ['pos']);
        $query = new class implements DashboardMetricQuery {
            public bool $resolved = false;

            public function resolve(DashboardContext $context, DashboardPeriod $period): array
            {
                $this->resolved = true;

                return ['unexpected' => true];
            }
        };
        $context = DashboardContext::forUser($business, $cashier);
        $service = new DashboardService([
            'sales.today' => new DashboardWidgetDefinition(
                key: 'sales.today',
                title: 'Privado',
                type: 'money',
                period: DashboardPeriod::TODAY,
                query: $query,
                requiredModules: ['pos'],
                requiredPermissions: ['permission.missing'],
            ),
        ]);

        $dashboard = $service->resolve($context);

        $this->assertSame([], $dashboard['widgets']);
        $this->assertFalse($query->resolved);
    }

    public function test_owner_simple_profile_has_unique_registry_keys_and_unknown_profiles_fail_closed(): void
    {
        $keys = DashboardProfiles::widgetKeys('owner_simple');

        $this->assertSame([
            'sales.today',
            'sales.this_month',
            'cash.current',
            'stock.alerts',
            'sales.top_product',
            'sales.estimated_margin',
        ], $keys);
        $this->assertCount(count(array_unique(array_keys(DashboardWidgetRegistry::all()))), DashboardWidgetRegistry::all());

        $this->expectException(\InvalidArgumentException::class);
        DashboardProfiles::widgetKeys('unknown_profile');
    }

    private function tenant(string $role, bool $branches = false, array $modules = ['pos', 'inventory', 'cash_register']): array
    {
        $business = Business::query()->create([
            'name' => 'Dashboard '.uniqid(),
            'slug' => 'dashboard-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        $main = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'MAIN', 'is_active' => true]);
        $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER', 'is_active' => true]);
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_product_images' => true,
            'use_branches' => $branches,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);
        foreach (array_unique([...$modules, ...($branches ? ['branches'] : [])]) as $module) {
            TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
        }
        $user = User::factory()->create([
            'business_id' => $business->id,
            'current_branch_id' => $main->id,
            'role' => $role,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        Permissions::assignRole($user, $role);

        return $branches ? [$business, $user, $main, $other] : [$business, $user, $main];
    }

    private function product(Business $business, Branch $branch, string $name, int $stock, int $minimum): Product
    {
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
            'cost_price' => 10,
            'sale_price' => 50,
            'stock' => $stock,
            'min_stock' => $minimum,
            'is_active' => true,
        ]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock]);

        return $product;
    }

    private function sale(Business $business, Branch $branch, User $user, Product $product, int $total, int $quantity, string $localDate = '2026-10-07 10:00:00', string $status = 'completed', string $timezone = 'America/Guatemala'): Sale
    {
        $at = Carbon::parse($localDate, $timezone)->utc();
        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'business_number' => Sale::query()->where('business_id', $business->id)->count() + 1,
            'customer_name' => 'Cliente',
            'total' => $total,
            'payment_method' => 'cash',
            'document_type' => 'receipt',
            'status' => $status,
            'cancelled_at' => $status === 'cancelled' ? $at : null,
            'created_by' => $user->id,
        ]);
        $sale->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        SaleItem::query()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $quantity,
            'unit_price' => $total / $quantity,
            'unit_cost' => 10,
            'total_cost' => $quantity * 10,
            'profit_amount' => $total - ($quantity * 10),
            'total' => $total,
            'total_before_discount' => $total,
            'total_after_discount' => $total,
        ]);

        return $sale;
    }
}
