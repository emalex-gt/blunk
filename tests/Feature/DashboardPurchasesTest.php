<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardProfileResolver;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\Metrics\PurchasesOverviewMetricQuery;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardPurchasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', 'America/Guatemala'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_purchases_role_resolves_to_one_purchases_overview_widget(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $context = $this->context($business, $user, $branch, [Permissions::PURCHASES_VIEW], ['purchases']);

        $this->assertSame('purchases', app(DashboardProfileResolver::class)->resolve($context));
        $dashboard = app(DashboardService::class)->resolve($context, 'purchases');
        $this->assertSame(['purchases.overview'], collect($dashboard['widgets'])->pluck('key')->all());
        $this->assertSame('purchases_overview', $dashboard['widgets'][0]['type']);
    }

    public function test_monthly_summary_top_supplier_and_products_are_tenant_branch_scoped_and_exclude_cancelled(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant(withOtherBranch: true);
        $supplier = $this->supplier($business, 'Principal');
        $otherSupplier = $this->supplier($business, 'Otra');
        $product = $this->product($business, 'Producto principal', 10);
        $otherProduct = $this->product($business, 'Otro producto', 10);
        $this->purchase($business, $branch, $user, $supplier, $product, 100, 10, '2026-09-20');
        $this->purchase($business, $branch, $user, $supplier, $product, 100, 10, '2026-10-20');
        $this->purchase($business, $branch, $user, $supplier, $product, 50, 5, '2026-10-10');
        $this->purchase($business, $branch, $user, $otherSupplier, $otherProduct, 999, 1, '2026-10-20', 'cancelled');
        $this->purchase($business, $otherBranch, $user, $otherSupplier, $otherProduct, 700, 10, '2026-10-20');
        [$foreign, $foreignUser, $foreignBranch] = $this->tenant();
        $this->purchase($foreign, $foreignBranch, $foreignUser, $this->supplier($foreign, 'Externo'), $this->product($foreign, 'Externo', 1), 9000, 10, '2026-10-20');

        $data = $this->data($this->context($business, $user, $branch, [Permissions::PURCHASES_VIEW], ['purchases']));

        $this->assertSame('150.00', $data['purchases_month']['total']);
        $this->assertSame(2, $data['purchases_month']['count']);
        $this->assertSame('75.00', $data['purchases_month']['average']);
        $this->assertSame(15.0, $data['purchases_month']['items_quantity']);
        $this->assertSame(1, $data['purchases_month']['supplier_count']);
        $this->assertSame('100.00', $data['purchases_month']['previous_total']);
        $this->assertSame(50.0, $data['purchases_month']['change_percent']);
        $this->assertSame('Principal', $data['top_suppliers'][0]['name']);
        $this->assertSame('Producto principal', $data['top_products'][0]['name']);
    }

    public function test_replenishment_is_inventory_gated_and_uses_branch_stock(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant(withOtherBranch: true);
        $out = $this->product($business, 'Agotado', 3);
        $low = $this->product($business, 'Bajo', 3);
        $normal = $this->product($business, 'Normal', 3);
        foreach ([[$out, 0, $branch], [$low, 2, $branch], [$normal, 9, $branch], [$out, 9, $otherBranch]] as [$product, $stock, $stockBranch]) ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $stockBranch->id, 'product_id' => $product->id, 'stock' => $stock]);

        $without = $this->data($this->context($business, $user, $branch, [Permissions::PURCHASES_VIEW], ['purchases']));
        $this->assertNull($without['stock']);
        $this->assertSame([], $without['replenishment']);

        $with = $this->data($this->context($business, $user, $branch, [Permissions::PURCHASES_VIEW, Permissions::INVENTORY_VIEW], ['purchases', 'inventory']));
        $this->assertSame(1, $with['stock']['out_of_stock_count']);
        $this->assertSame(1, $with['stock']['low_stock_count']);
        $this->assertSame(['Agotado', 'Bajo'], collect($with['replenishment'])->pluck('name')->all());
    }

    public function test_native_cost_changes_compare_two_valid_purchase_items_only(): void
    {
        [$business, $user, $branch] = $this->tenant();
        $supplier = $this->supplier($business, 'Costos');
        $up = $this->product($business, 'Producto A', 1);
        $down = $this->product($business, 'Producto B', 1);
        $single = $this->product($business, 'Producto C', 1);
        $this->purchase($business, $branch, $user, $supplier, $up, 10, 1, '2026-10-01', 'completed', 10);
        $this->purchase($business, $branch, $user, $supplier, $up, 12, 1, '2026-10-20', 'completed', 12);
        $this->purchase($business, $branch, $user, $supplier, $down, 20, 1, '2026-10-01', 'completed', 20);
        $this->purchase($business, $branch, $user, $supplier, $down, 18, 1, '2026-10-20', 'completed', 18);
        $this->purchase($business, $branch, $user, $supplier, $single, 30, 1, '2026-10-20', 'completed', 30);

        $changes = collect($this->data($this->context($business, $user, $branch, [Permissions::PURCHASES_VIEW], ['purchases']))['cost_changes'])->keyBy('name');

        $this->assertSame(20.0, $changes['Producto A']['change_percent']);
        $this->assertSame(-10.0, $changes['Producto B']['change_percent']);
        $this->assertFalse($changes->has('Producto C'));
    }

    public function test_purchases_frontend_delegates_to_typed_component(): void
    {
        $dashboard = file_get_contents(resource_path('js/Pages/Dashboard.tsx'));
        $purchases = file_get_contents(resource_path('js/Components/Dashboard/PurchasesDashboard.tsx'));

        $this->assertStringContainsString("widget.type === 'purchases_overview'", $dashboard);
        $this->assertStringContainsString('PurchasesDashboardData', $purchases);
        $this->assertStringContainsString('BarChart', $purchases);
    }

    private function data(DashboardContext $context): array { return (new PurchasesOverviewMetricQuery())->resolve($context, $context->period('current')); }
    private function context(Business $business, User $user, Branch $branch, array $permissions, array $modules): DashboardContext { return new DashboardContext($business, $user, $branch, true, $permissions, $modules, 'America/Guatemala'); }

    private function tenant(bool $withOtherBranch = false): array
    {
        $business = Business::query()->create(['name' => 'Purchases '.uniqid(), 'slug' => 'purchases-'.uniqid(), 'currency' => 'GTQ', 'country' => 'GT', 'is_active' => true]);
        $branch = Branch::query()->create(['business_id' => $business->id, 'name' => 'Principal', 'code' => 'PUR-'.uniqid(), 'is_active' => true]);
        TenantSetting::query()->create(['business_id' => $business->id, 'use_product_images' => true, 'use_branches' => $withOtherBranch, 'products_shared_across_branches' => true, 'pricing_scope' => 'global', 'allow_receipts' => true, 'allow_invoices' => false]);
        $user = User::factory()->create(['business_id' => $business->id, 'current_branch_id' => $branch->id, 'role' => 'purchases', 'is_active' => true]);
        if (! $withOtherBranch) return [$business, $user, $branch];
        $other = Branch::query()->create(['business_id' => $business->id, 'name' => 'Otra', 'code' => 'OTHER-'.uniqid(), 'is_active' => true]);
        return [$business, $user, $branch, $other];
    }

    private function supplier(Business $business, string $name): Supplier { return Supplier::query()->create(['business_id' => $business->id, 'name' => $name, 'is_active' => true]); }
    private function product(Business $business, string $name, float $minStock): Product { return Product::query()->create(['business_id' => $business->id, 'name' => $name, 'code' => 'P-'.uniqid(), 'cost_price' => 1, 'sale_price' => 2, 'stock' => 0, 'min_stock' => $minStock, 'is_active' => true]); }

    private function purchase(Business $business, Branch $branch, User $user, Supplier $supplier, Product $product, float $total, float $quantity, string $localDate, string $status = 'completed', ?float $unitCost = null): Purchase
    {
        $at = Carbon::parse($localDate.' 10:00:00', 'America/Guatemala')->utc();
        $purchase = Purchase::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'business_number' => Purchase::query()->where('business_id', $business->id)->count() + 1, 'status' => $status, 'total' => $total, 'payment_method' => 'cash', 'created_by' => $user->id]);
        $purchase->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        $cost = $unitCost ?? ($quantity > 0 ? $total / $quantity : $total);
        PurchaseItem::query()->create(['business_id' => $business->id, 'purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $quantity, 'unit_cost' => $cost, 'previous_cost' => $cost, 'new_average_cost' => $cost, 'total' => $total]);
        return $purchase;
    }
}
