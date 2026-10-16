<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\Sale;
use App\Models\TenantSetting;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardProfileResolver;
use App\Services\Dashboard\DashboardService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardBranchManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-14 15:00:00', 'America/Guatemala'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_resolves_to_branch_manager_and_receives_only_its_dedicated_workspace(): void
    {
        [$business, $admin, $branch] = $this->tenant('admin');
        Permissions::syncDefaults();
        Permissions::assignRole($admin, 'admin');
        $context = DashboardContext::forUser($business, $admin);

        $this->assertSame('branch_manager', app(DashboardProfileResolver::class)->resolve($context));
        $dashboard = app(DashboardService::class)->resolve($context, 'branch_manager');
        $this->assertSame(['branch_manager.overview'], collect($dashboard['widgets'])->pluck('key')->all());
        $this->assertEmpty($dashboard['actions']);
    }

    public function test_sales_are_active_branch_and_tenant_scoped_with_operational_yesterday_comparison(): void
    {
        [$business, $user, $branch, $other] = $this->tenant('admin', true);
        $this->sale($business, $branch, $user, 150, '2026-10-14 10:00:00');
        $this->sale($business, $branch, $user, 100, '2026-10-13 10:00:00');
        $this->sale($business, $other, $user, 900, '2026-10-14 10:00:00');
        [$foreign, $foreignUser, $foreignBranch] = $this->tenant('admin');
        $this->sale($foreign, $foreignBranch, $foreignUser, 5000, '2026-10-14 10:00:00');

        $data = $this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW], ['pos']));

        $this->assertSame('150.00', $data['sales']['total']);
        $this->assertSame(1, $data['sales']['count']);
        $this->assertSame('150.00', $data['sales']['average_ticket']);
        $this->assertSame('100.00', $data['sales']['previous_day_total']);
        $this->assertSame(50.0, $data['sales']['change_percent']);
    }

    public function test_zero_previous_day_and_tenant_timezone_boundary_are_handled_without_fake_comparison(): void
    {
        [$business, $user, $branch] = $this->tenant('admin');
        $this->sale($business, $branch, $user, 120, '2026-10-14 00:15:00');
        $this->sale($business, $branch, $user, 800, '2026-10-13 23:45:00');

        $data = $this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW], ['pos']));

        $this->assertSame('120.00', $data['sales']['total']);
        $this->assertSame('800.00', $data['sales']['previous_day_total']);
        $this->assertSame(-85.0, $data['sales']['change_percent']);

        Carbon::setTestNow(Carbon::parse('2026-10-16 15:00:00', 'America/Guatemala'));
        $zero = $this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW], ['pos']));
        $this->assertNull($zero['sales']['change_percent']);
    }

    public function test_cash_and_inventory_are_capability_gated_and_attention_never_links_to_an_unauthorized_report(): void
    {
        [$business, $user, $branch] = $this->tenant('admin');
        $product = Product::query()->create(['business_id' => $business->id, 'name' => 'Faltante', 'code' => 'MISSING-'.uniqid(), 'cost_price' => 1, 'sale_price' => 2, 'stock' => 0, 'min_stock' => 3, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => 0]);

        $inventoryOnly = $this->overview($this->context($business, $user, $branch, [Permissions::INVENTORY_VIEW], ['inventory']));
        $this->assertSame(1, $inventoryOnly['inventory']['out_of_stock_count']);
        $this->assertSame(route('stock.index', absolute: false), $inventoryOnly['attention'][0]['href']);
        $this->assertNull($inventoryOnly['cash']);

        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $user->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 10, 'opened_at' => now()]);
        $withCash = $this->overview($this->context($business, $user, $branch, [Permissions::CASH_REGISTER_VIEW], ['cash_register']));
        $this->assertSame('open', $withCash['cash']['status']);
        $this->assertArrayHasKey('expected_cash', $withCash['cash']);

        $closed = $this->overview($this->context($business, $user, Branch::query()->create(['business_id' => $business->id, 'name' => 'Cerrada', 'code' => 'CLOSED-'.uniqid(), 'is_active' => true]), [Permissions::CASH_REGISTER_VIEW], ['cash_register']));
        $this->assertSame('closed', $closed['cash']['status']);
        $this->assertSame('cash.closed', $closed['attention'][0]['key']);
    }

    public function test_route_operational_metrics_and_attention_require_the_full_existing_administrative_capability(): void
    {
        [$business, $user, $branch] = $this->tenant('admin');
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Ruta', 'country' => 'GT']);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'name' => 'Centro', 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $user->id, 'work_date' => today(), 'status' => 'open', 'started_at' => now()]);
        RouteVisit::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $user->id, 'status' => 'pending']);
        PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $user->id, 'status' => PreSale::STATUS_SUBMITTED, 'subtotal' => 10, 'discount_total' => 0, 'total' => 10]);

        $permissions = [Permissions::ROUTES_VIEW, Permissions::ROUTES_PRE_SALES_ADMIN_VIEW, Permissions::ROUTES_DELIVERY_RUNS_VIEW];
        $data = $this->overview($this->context($business, $user, $branch, $permissions, ['routes']));
        $this->assertSame(['open_work_days' => 1, 'pending_visits' => 1, 'operational_pre_sales' => 1, 'pending_delivery_stops' => 0], $data['routes']);
        $this->assertTrue(collect($data['attention'])->contains('key', 'routes.open_work_days'));
        $this->assertNull($this->overview($this->context($business, $user, $branch, [Permissions::ROUTES_VIEW], ['routes']))['routes']);
    }

    public function test_actions_are_authorized_and_branch_manager_never_returns_executive_financial_data(): void
    {
        [$business, $user, $branch] = $this->tenant('admin');
        $permissions = [Permissions::POS_VIEW, Permissions::CASH_REGISTER_VIEW, Permissions::INVENTORY_VIEW, Permissions::PURCHASES_CREATE, Permissions::PURCHASES_VIEW, Permissions::REPORTS_SALES_VIEW, Permissions::REPORTS_PROFIT_VIEW, Permissions::CREDITS_ACCOUNTS_VIEW];
        $data = $this->overview($this->context($business, $user, $branch, $permissions, ['pos', 'cash_register', 'inventory', 'purchases', 'reports', 'credits']));

        $this->assertSame(['sales.create', 'cash-register.index', 'stock.index', 'purchases.create', 'purchases.index', 'reports.sales'], collect($data['actions'])->pluck('key')->all());
        $this->assertArrayNotHasKey('margin', $data);
        $this->assertArrayNotHasKey('receivable', $data);
        $this->assertArrayNotHasKey('sales_month', $data);
    }

    public function test_dashboard_source_handles_partial_branch_manager_data_and_safe_attention_links(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));

        $this->assertStringContainsString("widget.type === 'branch_manager_overview'", $source);
        $this->assertStringContainsString('data.sales &&', $source);
        $this->assertStringContainsString('data.inventory &&', $source);
        $this->assertStringContainsString('data.routes &&', $source);
        $this->assertStringContainsString('item.href && <Link', $source);
    }

    private function overview(DashboardContext $context): array
    {
        return app(DashboardService::class)->resolve($context, 'branch_manager')['widgets'][0]['data'];
    }

    private function tenant(string $role, bool $otherBranch = false): array
    {
        $business = Business::query()->create(['name' => 'Branch '.uniqid(), 'slug' => 'branch-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'MAIN-'.uniqid(), 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id, 'use_product_images' => true, 'use_branches' => true, 'products_shared_across_branches' => true, 'pricing_scope' => 'global', 'allow_receipts' => true, 'allow_invoices' => false]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'pos', 'is_enabled' => true, 'enabled_at' => now()]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'branches', 'is_enabled' => true, 'enabled_at' => now()]);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
        $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $branch->id]);

        if (! $otherBranch) return [$business, $user, $branch];
        $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER-'.uniqid(), 'is_active' => true]);
        return [$business, $user, $branch, $other];
    }

    private function context(Business $business, User $user, Branch $branch, array $permissions, array $modules): DashboardContext
    {
        return new DashboardContext($business, $user, $branch, true, $permissions, $modules, 'America/Guatemala');
    }

    private function sale(Business $business, Branch $branch, User $user, int $total, string $date): void
    {
        $at = Carbon::parse($date, 'America/Guatemala')->utc();
        $sale = Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'business_number' => Sale::query()->where('business_id', $business->id)->count() + 1, 'customer_name' => 'Cliente', 'total' => $total, 'payment_method' => 'cash', 'document_type' => 'receipt', 'status' => 'completed', 'created_by' => $user->id]);
        $sale->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }
}
