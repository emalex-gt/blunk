<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
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

    public function test_stock_manager_resolves_to_a_single_dedicated_warehouse_workspace(): void
    {
        [$business, $user, $branch] = $this->tenant();
        Permissions::syncDefaults();
        Permissions::assignRole($user, 'stock_manager');

        $context = DashboardContext::forUser($business, $user);
        $this->assertSame('warehouse', app(DashboardProfileResolver::class)->resolve($context));

        $dashboard = app(DashboardService::class)->resolve($context, 'warehouse');
        $this->assertSame(['warehouse.overview'], collect($dashboard['widgets'])->pluck('key')->all());
        $this->assertSame([], $dashboard['actions']);
    }

    public function test_alerts_and_reservations_are_scoped_to_the_active_branch_and_tenant(): void
    {
        [$business, $user, $branch, $other] = $this->tenant(true);
        $out = $this->product($business, $branch, 'Sin stock', 0, 2);
        $low = $this->product($business, $branch, 'Bajo', 1, 2);
        $otherProduct = $this->product($business, $other, 'Otra sucursal', 0, 2);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $otherProduct->id, 'stock' => 10]);
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $low->id, 'source_type' => 'test', 'source_id' => 1, 'quantity' => 2.5, 'status' => 'active', 'created_by' => $user->id]);
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $other->id, 'product_id' => $out->id, 'source_type' => 'test', 'source_id' => 2, 'quantity' => 9, 'status' => 'active', 'created_by' => $user->id]);
        [$foreign, $foreignUser, $foreignBranch] = $this->tenant();
        $this->product($foreign, $foreignBranch, 'Otro tenant', 0, 2);

        $data = $this->overview($this->context($business, $user, $branch, [Permissions::INVENTORY_VIEW], ['inventory']));

        $this->assertSame(1, $data['inventory']['out_of_stock_count']);
        $this->assertSame(1, $data['inventory']['low_stock_count']);
        $this->assertSame(1, $data['reservations']['count']);
        $this->assertSame('2.5000', $data['reservations']['detail']);
        $this->assertSame(['stock.out', 'stock.low'], collect($data['attention'])->pluck('key')->all());
    }

    public function test_recent_movements_are_active_branch_only_newest_first_limited_and_timezone_scoped_today(): void
    {
        [$business, $user, $branch, $other] = $this->tenant(true);
        $product = $this->product($business, $branch, 'Producto principal', 10, 2);
        $otherProduct = $this->product($business, $other, 'Producto ajeno', 10, 2);
        foreach (range(1, 6) as $offset) {
            $this->movement($business, $branch, $product, $user, $offset, "2026-10-14 0{$offset}:00:00");
        }
        $this->movement($business, $other, $otherProduct, $user, 99, '2026-10-14 14:00:00');
        $this->movement($business, $branch, $product, $user, -1, '2026-10-13 23:45:00');

        $data = $this->overview($this->context($business, $user, $branch, [Permissions::INVENTORY_VIEW], ['inventory']));

        $this->assertSame(6, $data['movements_today_count']);
        $this->assertCount(5, $data['recent_movements']);
        $this->assertSame(6.0, $data['recent_movements'][0]['quantity']);
        $this->assertSame($product->id, $data['recent_movements'][0]['product_id']);
        $this->assertArrayNotHasKey('sale', $data['recent_movements'][0]);
    }

    public function test_stock_drill_down_prefers_the_report_only_when_authorized_and_reservations_are_not_alerts(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $product = $this->product($business, $branch, 'Agotado', 0, 1);
        StockReservation::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'source_type' => 'test', 'source_id' => 1, 'quantity' => 1, 'status' => 'active', 'created_by' => $user->id]);

        $fallback = $this->overview($this->context($business, $user, $branch, [Permissions::INVENTORY_VIEW], ['inventory']));
        $this->assertSame(route('stock.index', absolute: false), $fallback['attention'][0]['href']);

        $report = $this->overview($this->context($business, $user, $branch, [Permissions::INVENTORY_VIEW, Permissions::REPORTS_LOW_STOCK_VIEW], ['inventory', 'reports']));
        $this->assertSame(route('reports.low-stock', absolute: false), $report['attention'][0]['href']);
        $this->assertSame(1, $report['total_attention_count']);
    }

    public function test_authorized_actions_are_context_aware_and_never_duplicate_stock_index(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $permissions = [Permissions::INVENTORY_VIEW, Permissions::INVENTORY_ADJUST, Permissions::INVENTORY_TRANSFERS_CREATE, Permissions::INVENTORY_TRANSFERS_VIEW, Permissions::PRODUCTS_VIEW, Permissions::PRODUCT_LOCATIONS_VIEW, Permissions::REPORTS_LOW_STOCK_VIEW];
        $data = $this->overview($this->context($business, $user, $branch, $permissions, ['inventory', 'reports']));

        $this->assertSame(['stock.index', 'inventory.transfers.create', 'inventory.transfers.index', 'products.index', 'reports.low-stock', 'product-locations.index'], collect($data['actions'])->pluck('key')->all());
        $this->assertSame('Ajustar stock', $data['actions'][0]['label']);
        $this->assertSame(1, collect($data['actions'])->where('href', route('stock.index', absolute: false))->count());
        $this->assertArrayNotHasKey('margin', $data);
        $this->assertArrayNotHasKey('receivable', $data);
        $this->assertArrayNotHasKey('pending_transfers', $data);
    }

    public function test_missing_inventory_capability_hides_the_workspace_and_query_parameters_cannot_change_the_active_branch(): void
    {
        [$business, $user, $branch, $other] = $this->tenant(true);
        $this->product($business, $other, 'Otra', 0, 1);
        $hidden = app(DashboardService::class)->resolve($this->context($business, $user, $branch, [], ['inventory']), 'warehouse');
        $this->assertSame([], $hidden['widgets']);

        Permissions::syncDefaults();
        Permissions::assignRole($user, 'stock_manager');
        $this->actingAs($user)->get(route('dashboard', ['branch_id' => $other->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('dashboard.context.branch.id', $branch->id));
    }

    public function test_dashboard_source_renders_empty_states_and_signed_movements_safely(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));

        $this->assertStringContainsString("widget.type === 'warehouse_overview'", $source);
        $this->assertStringContainsString('No hay alertas de stock que requieran atención.', $source);
        $this->assertStringContainsString('No hay movimientos recientes en esta sucursal.', $source);
        $this->assertStringContainsString("quantity >= 0 ? '+' : ''", $source);
    }

    private function overview(DashboardContext $context): array
    {
        return app(DashboardService::class)->resolve($context, 'warehouse')['widgets'][0]['data'];
    }

    private function tenant(bool $otherBranch = false): array
    {
        $business = Business::query()->create(['name' => 'Warehouse '.uniqid(), 'slug' => 'warehouse-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'MAIN-'.uniqid(), 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id, 'use_product_images' => true, 'use_branches' => true, 'products_shared_across_branches' => true, 'pricing_scope' => 'global', 'allow_receipts' => true, 'allow_invoices' => false]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'inventory', 'is_enabled' => true, 'enabled_at' => now()]);
        TenantModule::query()->create(['business_id' => $business->id, 'module' => 'branches', 'is_enabled' => true, 'enabled_at' => now()]);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => 'stock_manager', 'is_active' => true]);
        $user->branchAccesses()->create(['business_id' => $business->id, 'branch_id' => $branch->id]);

        if (! $otherBranch) return [$business, $user, $branch];
        $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER-'.uniqid(), 'is_active' => true]);
        return [$business, $user, $branch, $other];
    }

    private function context(Business $business, User $user, Branch $branch, array $permissions, array $modules): DashboardContext
    {
        return new DashboardContext($business, $user, $branch, true, $permissions, $modules, 'America/Guatemala');
    }

    private function product(Business $business, Branch $branch, string $name, int $stock, int $minimum): Product
    {
        $product = Product::query()->create(['business_id' => $business->id, 'name' => $name, 'code' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(), 'cost_price' => 1, 'sale_price' => 2, 'stock' => $stock, 'min_stock' => $minimum, 'is_active' => true]);
        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock]);
        return $product;
    }

    private function movement(Business $business, Branch $branch, Product $product, User $user, int $quantity, string $date): void
    {
        $at = Carbon::parse($date, 'America/Guatemala')->utc();
        $movement = StockMovement::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'type' => 'adjustment', 'quantity' => $quantity, 'previous_stock' => 10, 'new_stock' => 10 + $quantity, 'note' => 'Fixture', 'created_by' => $user->id]);
        $movement->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }
}
