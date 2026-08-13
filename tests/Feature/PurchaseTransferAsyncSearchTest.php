<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Supplier;
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

    public function test_purchase_hydrates_suppliers_by_ids_for_drafts(): void
    {
        [$business, $user] = $this->tenant('purchases', ['purchases']);
        $supplier = Supplier::query()->create([
            'business_id' => $business->id,
            'name' => 'Proveedor fuera de lista inicial',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson(route('purchases.suppliers.search', ['ids' => (string) $supplier->id]))
            ->assertOk()
            ->assertJsonPath('suppliers.0.id', $supplier->id)
            ->assertJsonPath('suppliers.0.name', 'Proveedor fuera de lista inicial');
    }

    public function test_purchase_and_transfer_create_sources_require_explicit_confirmation(): void
    {
        $purchaseSource = file_get_contents(resource_path('js/Pages/Purchases/Create.tsx'));
        $transferSource = file_get_contents(resource_path('js/Pages/Inventory/Transfers/Create.tsx'));

        $this->assertStringContainsString('¿Has revisado la información de esta compra?', $purchaseSource);
        $this->assertStringContainsString('Sí, guardar compra', $purchaseSource);
        $this->assertStringContainsString('onSubmit={(event) => event.preventDefault()}', $purchaseSource);
        $this->assertStringContainsString('onClick={() => submit()}', $purchaseSource);
        $this->assertStringContainsString('¿Has revisado la información de este traslado?', $transferSource);
        $this->assertStringContainsString('Sí, guardar traslado', $transferSource);
        $this->assertStringContainsString('onClick={requestTransferConfirmation}', $transferSource);
    }

    public function test_purchase_create_source_uses_readable_purchase_search_and_cart_layout(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Purchases/Create.tsx'));

        $this->assertStringContainsString('min-[1180px]:grid-cols-2', $source);
        $this->assertStringNotContainsString('lg:grid-cols-3 xl:grid-cols-4', $source);
        $this->assertStringContainsString('line-clamp-2', $source);
        $this->assertStringContainsString('Costo actual', $source);
        $this->assertStringContainsString('Último costo', $source);
        $this->assertStringContainsString('Costo unitario', $source);
        $this->assertStringContainsString('Subtotal', $source);
        $this->assertStringContainsString('purchaseDetailsExpanded', $source);
        $this->assertStringContainsString('Guardar datos', $source);
        $this->assertStringContainsString('Editar datos', $source);
        $this->assertStringContainsString('Datos de compra', $source);
        $this->assertStringNotContainsString('min-h-0 flex-1 overflow-y-auto p-4', $source);
    }

    public function test_stock_menu_points_to_paginated_stock_index_not_full_catalog_quick_page(): void
    {
        $source = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.tsx'));

        $this->assertStringContainsString("href: route('stock.index')", $source);
        $this->assertStringNotContainsString("href: route('stock.quick')", $source);
    }

    public function test_pos_source_has_cash_change_and_temporary_manual_price_state(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Sales/POS.tsx'));

        $this->assertStringContainsString("const [cashReceived, setCashReceived] = useState('');", $source);
        $this->assertStringContainsString('const cashPaymentAmount = useMemo(', $source);
        $this->assertStringContainsString('Efectivo recibido', $source);
        $this->assertStringContainsString('Cambio', $source);
        $this->assertStringContainsString('type ManualPriceDraft = {', $source);
        $this->assertStringContainsString('const [manualPriceDraft, setManualPriceDraft]', $source);
        $this->assertStringContainsString('function closeManualPriceModal()', $source);
        $this->assertStringContainsString('function resetLinePrice(productId: number)', $source);
        $this->assertStringContainsString('onClick={applyManualPriceDraft}', $source);
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

    public function test_stock_index_is_paginated_and_filters_server_side(): void
    {
        [$business, $user, $branch] = $this->tenant('stock_manager', ['inventory']);
        [$otherBusiness] = $this->tenant('stock_manager', ['inventory'], 'Other stock tenant');
        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal secundaria',
            'is_active' => true,
        ]);
        $lastProduct = null;

        foreach (range(1, 30) as $index) {
            $product = $this->product($business, "Stock paginado {$index}", "ST-{$index}", "BAR-{$index}");
            $lastProduct = $product;
            ProductBranchStock::query()->create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'stock' => $index,
            ]);
        }

        $this->product($otherBusiness, 'Stock paginado otro tenant', 'ST-OTRO', 'BAR-OTRO');
        ProductBranchStock::query()->create([
            'business_id' => $business->id,
            'branch_id' => $otherBranch->id,
            'product_id' => $lastProduct->id,
            'stock' => 99,
        ]);

        $this->actingAs($user)
            ->get(route('stock.index', ['per_page' => 25]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Index')
                ->has('products.data', 25)
                ->where('products.total', 30));

        $this->actingAs($user)
            ->get(route('stock.index', ['search' => 'ST-30', 'per_page' => 25]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Index')
                ->has('products.data', 1)
                ->where('products.data.0.code', 'ST-30')
                ->where('products.data.0.stock', 30));

        $this->actingAs($user)
            ->get(route('stock.index', ['search' => 'BAR-30', 'per_page' => 25]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Index')
                ->has('products.data', 1)
                ->where('products.data.0.code', 'ST-30')
                ->where('products.data.0.stock', 30));

        $this->actingAs($user)
            ->get(route('stock.index', ['search' => 'ST-OTRO', 'per_page' => 25]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Index')
                ->has('products.data', 0)
                ->where('products.total', 0));
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

    private function product(Business $business, string $name, ?string $code = null, ?string $barcode = null): Product
    {
        return Product::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => $code ?? uniqid('P-'),
            'barcode' => $barcode,
            'cost_price' => 10,
            'sale_price' => 20,
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }
}
