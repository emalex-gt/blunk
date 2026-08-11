<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchaseTransferAsyncSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_purchase_create_page_does_not_send_full_product_catalog(): void
    {
        [$business, $user] = $this->tenant('purchases', ['purchases']);
        $this->product($business, 'Bomba de agua');
        $this->product($business, 'Filtro de aceite');

        $this->actingAs($user)
            ->get(route('purchases.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Purchases/Create')
                ->where('products', []));
    }

    public function test_transfer_create_page_does_not_send_full_product_catalog(): void
    {
        [$business, $user] = $this->tenant('stock_manager', ['branches']);
        $this->product($business, 'Bomba de agua');

        $this->actingAs($user)
            ->get(route('inventory.transfers.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Transfers/Create')
                ->where('products', []));
    }

    public function test_purchase_product_search_is_limited_and_tenant_scoped(): void
    {
        [$business, $user] = $this->tenant('purchases', ['purchases']);
        [$otherBusiness] = $this->tenant('purchases', ['purchases'], 'Other tenant');

        foreach (range(1, 35) as $index) {
            $this->product($business, "Bomba {$index}", "BOMBA-{$index}");
        }
        $this->product($otherBusiness, 'Bomba otro tenant', 'BOMBA-OTRO');

        $response = $this->actingAs($user)
            ->getJson(route('purchases.products.search', ['q' => 'Bomba', 'limit' => 30]))
            ->assertOk()
            ->json('products');

        $this->assertCount(30, $response);
        $this->assertNotContains('BOMBA-OTRO', collect($response)->pluck('code')->all());
    }

    public function test_transfer_product_search_is_limited_tenant_scoped_and_includes_source_availability(): void
    {
        [$business, $user, $branch] = $this->tenant('stock_manager', ['branches']);
        [$otherBusiness] = $this->tenant('stock_manager', ['branches'], 'Other tenant');
        $product = $this->product($business, 'Bomba traslado', 'TR-1');
        $this->product($otherBusiness, 'Bomba traslado otro tenant', 'TR-OTRO');

        ProductBranchStock::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => 12,
        ]);

        $products = $this->actingAs($user)
            ->getJson(route('inventory.transfers.products.search', [
                'q' => 'Bomba traslado',
                'source_branch_id' => $branch->id,
            ]))
            ->assertOk()
            ->json('products');

        $this->assertCount(1, $products);
        $this->assertSame($product->id, $products[0]['id']);
        $this->assertSame(12.0, (float) $products[0]['stock']);
        $this->assertSame(12.0, (float) $products[0]['available_stock']);
    }

    public function test_purchase_hydrates_products_by_ids_for_drafts(): void
    {
        [$business, $user] = $this->tenant('purchases', ['purchases']);
        $product = $this->product($business, 'Hidratado compra', 'HYD-P');

        $this->actingAs($user)
            ->getJson(route('purchases.products.search', ['ids' => (string) $product->id]))
            ->assertOk()
            ->assertJsonPath('products.0.id', $product->id);
    }

    public function test_transfer_hydrates_products_by_ids_for_drafts(): void
    {
        [$business, $user, $branch] = $this->tenant('stock_manager', ['branches']);
        $product = $this->product($business, 'Hidratado traslado', 'HYD-T');

        ProductBranchStock::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => 5,
        ]);

        $this->actingAs($user)
            ->getJson(route('inventory.transfers.products.search', [
                'ids' => (string) $product->id,
                'source_branch_id' => $branch->id,
            ]))
            ->assertOk()
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.available_stock', 5);
    }

    private function tenant(string $role, array $modules, string $name = 'Tenant async'): array
    {
        $business = Business::query()->create([
            'name' => $name,
            'country' => 'GT',
            'currency' => 'GTQ',
            'is_active' => true,
        ]);

        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_branches' => true,
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

        $branch = BranchInventory::defaultBranch($business->id);
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => $role,
            'is_active' => true,
            'current_branch_id' => $branch->id,
        ]);
        Permissions::assignRole($user, $role);

        return [$business, $user, $branch];
    }

    private function product(Business $business, string $name, ?string $code = null): Product
    {
        return Product::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => $code ?? uniqid('P-'),
            'barcode' => null,
            'cost_price' => 10,
            'sale_price' => 20,
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }
}
