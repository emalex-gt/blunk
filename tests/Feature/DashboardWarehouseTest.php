<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\StockMovement;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardWarehouseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Carbon::setTestNow(Carbon::parse('2026-10-14 15:00:00', 'America/Guatemala')); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_stock_manager_resolves_to_the_warehouse_widget_with_only_authorized_quick_actions(): void
    {
        [$business, $user, $branch] = $this->tenant();
        Permissions::syncDefaults();
        Permissions::assignRole($user, 'stock_manager');
        $context = DashboardContext::forUser($business, $user);

        $this->assertSame('warehouse', app(DashboardProfileResolver::class)->resolve($context));
        $dashboard = app(DashboardService::class)->resolve($context, 'warehouse');
        $this->assertSame(['warehouse.overview'], collect($dashboard['widgets'])->pluck('key')->all());
        $this->assertSame(['stock.index.warehouse', 'inventory.transfers.create', 'inventory.transfers.index', 'products.index'], collect($dashboard['actions'])->pluck('key')->all());
    }

    public function test_stock_summary_critical_stock_and_reservations_are_scoped_to_active_branch_and_tenant(): void
    {
        [$business, $user, $branch, $other] = $this->tenant(true);
        $negative = $this->product($business, $branch, 'Negativo', -2, 2);
        $out = $this->product($business, $branch, 'Agotado', 0, 2);
        $low = $this->product($business, $branch, 'Bajo', 1, 2);
        $normal = $this->product($business, $branch, 'Normal', 8, 2);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $other->id, 'product_id' => $negative->id, 'stock' => 99]);
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $low->id, 'source_type' => 'test', 'source_id' => 1, 'quantity' => 2.5, 'status' => 'active', 'created_by' => $user->id]);
        [$foreign, $foreignUser, $foreignBranch] = $this->tenant();
        $this->product($foreign, $foreignBranch, 'Ajeno', 0, 2);

        $data = $this->overview($this->context($business, $user, $branch, [Permissions::INVENTORY_VIEW], ['inventory']));

        $this->assertSame(4, $data['stock_summary']['active_skus']);
        $this->assertSame(7.0, $data['stock_summary']['physical_units']);
        $this->assertSame(1, $data['stock_summary']['negative_stock_count']);
        $this->assertSame(2, $data['inventory']['out_of_stock_count']);
        $this->assertSame(1, $data['inventory']['low_stock_count']);
        $this->assertSame(['Negativo', 'Agotado', 'Bajo'], collect($data['critical_stock'])->pluck('name')->all());
        $this->assertSame(1, $data['reservations']['count']);
        $this->assertSame('2.5000', $data['reservations']['detail']);
        $this->assertFalse(collect($data['critical_stock'])->pluck('product_id')->contains($normal->id));
        $this->assertSame(route('stock.index', absolute: false), $data['attention'][0]['href']);

        $report = $this->overview($this->context($business, $user, $branch, [Permissions::INVENTORY_VIEW, Permissions::REPORTS_LOW_STOCK_VIEW], ['inventory', 'reports']));
        $this->assertSame(route('reports.low-stock', absolute: false), $report['attention'][0]['href']);
    }

    public function test_movement_summary_trend_top_products_and_recent_activity_use_signed_quantities_and_branch_scope(): void
    {
        [$business, $user, $branch, $other] = $this->tenant(true);
        $first = $this->product($business, $branch, 'Producto A', 10, 2);
        $second = $this->product($business, $branch, 'Producto B', 10, 2);
        $otherProduct = $this->product($business, $other, 'Otra sucursal', 10, 2);
        $this->movement($business, $branch, $first, $user, 10, '2026-10-14 09:00:00');
        $this->movement($business, $branch, $first, $user, -4, '2026-10-14 10:00:00');
        $this->movement($business, $branch, $second, $user, -8, '2026-10-14 11:00:00');
        $this->movement($business, $branch, $second, $user, 0, '2026-10-14 12:00:00');
        $this->movement($business, $other, $otherProduct, $user, 99, '2026-10-14 12:00:00');
        $this->movement($business, $branch, $first, $user, -99, '2026-09-01 10:00:00');

        $data = $this->overview($this->context($business, $user, $branch, [Permissions::INVENTORY_VIEW], ['inventory']));

        $this->assertSame(4, $data['movements_today']['count']);
        $this->assertSame(1, $data['movements_today']['entries_count']);
        $this->assertSame(2, $data['movements_today']['exits_count']);
        $this->assertSame(10.0, $data['movements_today']['entries_quantity']);
        $this->assertSame(12.0, $data['movements_today']['exits_quantity']);
        $this->assertSame(-2.0, $data['movements_today']['net_quantity']);
        $this->assertCount(14, $data['movement_trend']);
        $this->assertSame(4, $data['movement_trend'][13]['movements']);
        $this->assertSame('Producto A', $data['top_moved_products'][0]['name']);
        $this->assertSame(14.0, $data['top_moved_products'][0]['total_moved']);
        $this->assertCount(5, $data['recent_movements']);
        $this->assertArrayNotHasKey('sale', $data['recent_movements'][0]);
    }

    public function test_transfers_require_permission_and_are_scoped_to_active_branch(): void
    {
        [$business, $user, $branch, $other] = $this->tenant(true);
        $third = Branch::query()->create(['business_id' => $business->id, 'name' => 'Tercera', 'code' => 'THIRD-'.uniqid(), 'is_active' => true]);
        $product = $this->product($business, $branch, 'Producto', 3, 1);
        $this->transfer($business, $other, $branch, $user, $product, 5);
        $this->transfer($business, $branch, $other, $user, $product, 3);
        $this->transfer($business, $other, $third, $user, $product, 99);
        $this->transfer($business, $branch, $other, $user, $product, 88, 'cancelled');

        $without = $this->overview($this->context($business, $user, $branch, [Permissions::INVENTORY_VIEW], ['inventory']));
        $this->assertNull($without['transfers']);
        $this->assertSame([], $without['recent_transfers']);

        $with = $this->overview($this->context($business, $user, $branch, [Permissions::INVENTORY_VIEW, Permissions::INVENTORY_TRANSFERS_VIEW], ['inventory']));
        $this->assertSame(2, $with['transfers']['month_count']);
        $this->assertSame(1, $with['transfers']['inbound_count']);
        $this->assertSame(1, $with['transfers']['outbound_count']);
        $this->assertSame(5.0, $with['transfers']['inbound_units']);
        $this->assertSame(3.0, $with['transfers']['outbound_units']);
        $this->assertCount(2, $with['recent_transfers']);
        $this->assertSame(route('inventory.transfers.show', $with['recent_transfers'][0]['id'], absolute: false), $with['recent_transfers'][0]['href']);
    }

    public function test_dashboard_source_uses_typed_warehouse_component_without_financial_fields(): void
    {
        $dashboard = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));
        $warehouse = file_get_contents(resource_path('js/Components/Dashboard/WarehouseDashboard.tsx'));

        $this->assertStringContainsString('WarehouseDashboardData', $dashboard);
        $this->assertStringContainsString("widget.type === 'warehouse_overview'", $dashboard);
        $this->assertStringNotContainsString('WarehouseOverviewCard', $dashboard);
        $this->assertStringContainsString('movement_trend', $warehouse);
        $this->assertStringContainsString('Productos más movidos', $warehouse);
        $this->assertStringNotContainsString('inventory_value', $warehouse);
    }

    public function test_missing_inventory_capability_hides_workspace_and_query_parameters_cannot_change_active_branch(): void
    {
        [$business, $user, $branch, $other] = $this->tenant(true);
        $hidden = app(DashboardService::class)->resolve($this->context($business, $user, $branch, [], ['inventory']), 'warehouse');
        $this->assertSame([], $hidden['widgets']);
        Permissions::syncDefaults();
        Permissions::assignRole($user, 'stock_manager');
        $this->actingAs($user)->get(route('dashboard', ['branch_id' => $other->id]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('dashboard.context.branch.id', $branch->id));
    }

    private function overview(DashboardContext $context): array { return app(DashboardService::class)->resolve($context, 'warehouse')['widgets'][0]['data']; }
    private function context(Business $business, User $user, Branch $branch, array $permissions, array $modules): DashboardContext { return new DashboardContext($business, $user, $branch, true, $permissions, $modules, 'America/Guatemala'); }

    private function tenant(bool $otherBranch = false): array
    {
        $business = Business::query()->create(['name' => 'Warehouse '.uniqid(), 'slug' => 'warehouse-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'MAIN-'.uniqid(), 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id, 'use_product_images' => true, 'use_branches' => true, 'products_shared_across_branches' => true, 'pricing_scope' => 'global', 'allow_receipts' => true, 'allow_invoices' => false]);
        foreach (['inventory', 'branches'] as $module) TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => 'stock_manager', 'is_active' => true]);
        $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $branch->id]);
        if (! $otherBranch) return [$business, $user, $branch];
        $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER-'.uniqid(), 'is_active' => true]);
        return [$business, $user, $branch, $other];
    }

    private function product(Business $business, Branch $branch, string $name, float $stock, float $minimum): Product
    {
        $product = Product::query()->create(['business_id' => $business->id, 'name' => $name, 'code' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(), 'cost_price' => 1, 'sale_price' => 2, 'stock' => $stock, 'min_stock' => $minimum, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock]);
        return $product;
    }

    private function movement(Business $business, Branch $branch, Product $product, User $user, float $quantity, string $date): void
    {
        $at = Carbon::parse($date, 'America/Guatemala')->utc();
        $movement = StockMovement::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'type' => 'adjustment', 'quantity' => $quantity, 'previous_stock' => 10, 'new_stock' => 10 + $quantity, 'note' => 'Fixture', 'created_by' => $user->id]);
        $movement->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    private function transfer(Business $business, Branch $from, Branch $to, User $user, Product $product, int $quantity, string $status = 'completed'): void
    {
        $transfer = InventoryTransfer::query()->create(['business_id' => $business->id, 'from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'status' => $status, 'created_by' => $user->id]);
        InventoryTransferLine::query()->create(['business_id' => $business->id, 'inventory_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => $quantity]);
    }
}
