<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\PreSale;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Purchase;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
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

    protected function setUp(): void { parent::setUp(); Carbon::setTestNow(Carbon::parse('2026-10-14 15:00:00', 'America/Guatemala')); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_admin_resolves_to_branch_manager_workspace_with_authorized_quick_actions(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        Permissions::syncDefaults();
        Permissions::assignRole($admin, 'admin');
        $this->assertSame('branch_manager', app(DashboardProfileResolver::class)->resolve(DashboardContext::forUser($business, $admin)));

        $dashboard = app(DashboardService::class)->resolve($this->context($business, $admin, $branch, [Permissions::POS_VIEW, Permissions::CASH_REGISTER_VIEW, Permissions::INVENTORY_VIEW, Permissions::ROUTES_PRE_SALES_ADMIN_VIEW], ['pos', 'cash_register', 'inventory', 'routes']), 'branch_manager');
        $this->assertSame(['branch_manager.overview'], collect($dashboard['widgets'])->pluck('key')->all());
        $this->assertSame(['sales.create.branch-manager', 'cash-register.index.branch-manager', 'stock.index.branch-manager', 'routes.global-operations.branch-manager'], collect($dashboard['actions'])->pluck('key')->all());
    }

    public function test_sales_today_and_trend_are_tenant_branch_scoped_and_exclude_cancelled(): void
    {
        [$business, $user, $branch, $other] = $this->tenant(true);
        $this->sale($business, $branch, $user, 150, '2026-10-14 10:00:00');
        $this->sale($business, $branch, $user, 100, '2026-10-13 10:00:00');
        $this->sale($business, $branch, $user, 999, '2026-10-14 11:00:00', 'cancelled');
        $this->sale($business, $other, $user, 900, '2026-10-14 10:00:00');
        [$foreign, $foreignUser, $foreignBranch] = $this->tenant();
        $this->sale($foreign, $foreignBranch, $foreignUser, 5000, '2026-10-14 10:00:00');

        $data = $this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW], ['pos']));
        $this->assertSame('150.00', $data['sales_today']['total']);
        $this->assertSame(1, $data['sales_today']['count']);
        $this->assertSame('100.00', $data['sales_today']['previous_day_total']);
        $this->assertSame(50.0, $data['sales_today']['change_percent']);
        $this->assertCount(7, $data['sales_trend']);
        $this->assertSame(150.0, $data['sales_trend'][6]['total']);
        Carbon::setTestNow(Carbon::parse('2026-10-16 15:00:00', 'America/Guatemala'));
        $this->assertNull($this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW], ['pos']))['sales_today']['change_percent']);
    }

    public function test_captured_sale_payments_drive_collections_and_payment_mix_by_branch(): void
    {
        [$business, $user, $branch, $other] = $this->tenant(true);
        $cashSale = $this->sale($business, $branch, $user, 100, '2026-10-14 09:00:00');
        $cardSale = $this->sale($business, $branch, $user, 50, '2026-10-14 10:00:00');
        $otherSale = $this->sale($business, $other, $user, 80, '2026-10-14 10:00:00');
        $this->payment($business, $cashSale, 'cash', 60, 'captured');
        $this->payment($business, $cardSale, 'card', 40, 'captured');
        $this->payment($business, $cashSale, 'cash', 99, 'reversed');
        $this->payment($business, $otherSale, 'cash', 80, 'captured');

        $data = $this->overview($this->context($business, $user, $branch, [Permissions::POS_VIEW], ['pos']));
        $this->assertSame('100.00', $data['collections_today']['total']);
        $this->assertSame(2, $data['collections_today']['count']);
        $this->assertSame(['Efectivo', 'Tarjeta'], collect($data['payment_mix'])->pluck('label')->all());
    }

    public function test_operational_blocks_are_capability_gated_and_purchase_top_product_activity_remain_safe(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $product = $this->product($business, $branch, 'Producto A', 0, 2);
        $otherProduct = $this->product($business, $branch, 'Producto B', 1, 2);
        $sale = $this->sale($business, $branch, $user, 70, '2026-10-14 10:00:00');
        $this->saleItem($business, $sale, $product, 5, 50);
        $this->saleItem($business, $sale, $otherProduct, 2, 20);
        $this->purchase($business, $branch, $user, 80, 'completed');
        $this->purchase($business, $branch, $user, 900, 'cancelled');
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'source_type' => 'test', 'source_id' => 1, 'quantity' => 2, 'status' => 'active', 'created_by' => $user->id]);
        CashRegisterSession::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'opened_by' => $user->id, 'status' => 'open', 'opening_amount' => 0, 'expected_cash' => 10, 'opened_at' => now()]);

        $permissions = [Permissions::POS_VIEW, Permissions::SALES_VIEW, Permissions::CASH_REGISTER_VIEW, Permissions::INVENTORY_VIEW, Permissions::PURCHASES_VIEW];
        $data = $this->overview($this->context($business, $user, $branch, $permissions, ['pos', 'cash_register', 'inventory', 'purchases']));
        $this->assertSame('open', $data['cash']['status']);
        $this->assertSame(1, $data['inventory']['out_of_stock_count']);
        $this->assertSame(1, $data['inventory']['reservations']['count']);
        $this->assertSame('80.00', $data['purchases_today']['total']);
        $this->assertSame('Producto A', $data['top_products_today'][0]['name']);
        $this->assertEqualsCanonicalizing(
            ['sale', 'purchase'],
            collect($data['recent_activity'])->pluck('type')->all()
        );
        $this->assertArrayNotHasKey('profit', $data);
        $this->assertArrayNotHasKey('margin', $data);
        $this->assertArrayNotHasKey('inventory_value', $data);
        $this->assertNull($this->overview($this->context($business, $user, $branch, [], []))['inventory']);
    }

    public function test_routes_require_full_existing_capability(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $customer = Customer::query()->create(['business_id' => $business->id, 'name' => 'Ruta', 'country' => 'GT']);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'name' => 'Centro', 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_zone_id' => $zone->id, 'seller_id' => $user->id, 'work_date' => today(), 'status' => 'open', 'started_at' => now()]);
        RouteVisit::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $user->id, 'status' => 'pending']);
        PreSale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'route_work_day_id' => $workDay->id, 'route_zone_id' => $zone->id, 'customer_id' => $customer->id, 'seller_id' => $user->id, 'status' => PreSale::STATUS_SUBMITTED, 'subtotal' => 10, 'discount_total' => 0, 'total' => 10]);
        $permissions = [Permissions::ROUTES_VIEW, Permissions::ROUTES_PRE_SALES_ADMIN_VIEW, Permissions::ROUTES_DELIVERY_RUNS_VIEW];
        $data = $this->overview($this->context($business, $user, $branch, $permissions, ['routes']));
        $this->assertSame(['open_work_days' => 1, 'pending_visits' => 1, 'operational_pre_sales' => 1, 'pending_delivery_stops' => 0], $data['routes']);
        $this->assertNull($this->overview($this->context($business, $user, $branch, [Permissions::ROUTES_VIEW], ['routes']))['routes']);
    }

    public function test_dashboard_source_uses_typed_component_without_inline_legacy_card(): void
    {
        $dashboard = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));
        $component = file_get_contents(resource_path('js/Components/Dashboard/BranchManagerDashboard.tsx'));
        $this->assertStringContainsString('BranchManagerDashboardData', $dashboard);
        $this->assertStringContainsString("widget.type === 'branch_manager_overview'", $dashboard);
        $this->assertStringNotContainsString('BranchManagerOverviewCard', $dashboard);
        $this->assertStringContainsString('sales_trend', $component);
        $this->assertStringContainsString('payment_mix', $component);
        $this->assertStringContainsString('Actividad reciente', $component);
    }

    private function overview(DashboardContext $context): array { return app(DashboardService::class)->resolve($context, 'branch_manager')['widgets'][0]['data']; }
    private function context(Business $business, User $user, Branch $branch, array $permissions, array $modules): DashboardContext { return new DashboardContext($business, $user, $branch, true, $permissions, $modules, 'America/Guatemala'); }
    private function tenant(bool $otherBranch = false): array { $business = Business::query()->create(['name' => 'Branch '.uniqid(), 'slug' => 'branch-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]); $branch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'MAIN-'.uniqid(), 'is_active' => true]); TenantSetting::query()->create(['business_id' => $business->id, 'use_product_images' => true, 'use_branches' => true, 'products_shared_across_branches' => true, 'pricing_scope' => 'global', 'allow_receipts' => true, 'allow_invoices' => false]); foreach (['pos', 'branches'] as $module) TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]); $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]); $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $branch->id]); if (! $otherBranch) return [$business, $user, $branch]; $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER-'.uniqid(), 'is_active' => true]); return [$business, $user, $branch, $other]; }
    private function sale(Business $business, Branch $branch, User $user, float $total, string $date, string $status = 'completed'): Sale { $at = Carbon::parse($date, 'America/Guatemala')->utc(); $sale = Sale::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'business_number' => Sale::query()->where('business_id', $business->id)->count() + 1, 'customer_name' => 'Cliente', 'total' => $total, 'payment_method' => 'cash', 'document_type' => 'receipt', 'status' => $status, 'created_by' => $user->id]); $sale->forceFill(['created_at' => $at, 'updated_at' => $at])->save(); return $sale; }
    private function payment(Business $business, Sale $sale, string $method, float $amount, string $status): void { SalePayment::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'method' => $method, 'amount' => $amount, 'status' => $status, 'collected_at' => now()]); }
    private function product(Business $business, Branch $branch, string $name, float $stock, float $minimum): Product { $product = Product::query()->create(['business_id' => $business->id, 'name' => $name, 'code' => 'P-'.uniqid(), 'cost_price' => 1, 'sale_price' => 2, 'stock' => $stock, 'min_stock' => $minimum, 'is_active' => true]); ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock]); return $product; }
    private function saleItem(Business $business, Sale $sale, Product $product, int $quantity, float $total): void { SaleItem::query()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $quantity, 'unit_price' => $total / $quantity, 'unit_cost' => 0, 'total_cost' => 0, 'profit_amount' => 0, 'total' => $total, 'total_before_discount' => $total, 'total_after_discount' => $total]); }
    private function purchase(Business $business, Branch $branch, User $user, float $total, string $status): void { Purchase::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'business_number' => Purchase::query()->where('business_id', $business->id)->count() + 1, 'status' => $status, 'total' => $total, 'payment_method' => 'cash', 'created_by' => $user->id]); }
}
