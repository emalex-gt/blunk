<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\StockMovement;
use App\Models\StockReservation;
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

    public function test_stock_index_uses_operational_async_search_source(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Stock/Index.tsx'));

        $this->assertStringContainsString("route('stock.products.search')", $source);
        $this->assertStringContainsString("route('stock.adjustments.store')", $source);
        $this->assertStringContainsString('Ajustar stock', $source);
        $this->assertStringContainsString('Confirmar ajuste', $source);
        $this->assertStringContainsString('Nota / motivo', $source);
        $this->assertStringNotContainsString('<table', $source);
    }

    public function test_stock_index_opens_without_product_catalog_payload(): void
    {
        [$business, $user] = $this->tenant('stock_manager', ['inventory']);
        foreach (range(1, 30) as $index) {
            $this->product($business, "Stock inicial {$index}", "INI-{$index}");
        }

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Index')
                ->missing('products')
                ->where('can_adjust_stock', true)
                ->where('allow_negative_stock', false));
    }

    public function test_stock_product_search_is_limited_tenant_scoped_branch_scoped_and_returns_availability(): void
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

        StockReservation::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $lastProduct->id,
            'source_type' => 'manual_test',
            'source_id' => 1,
            'quantity' => 7,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $products = $this->actingAs($user)
            ->getJson(route('stock.products.search', ['q' => 'Stock paginado', 'limit' => 50]))
            ->assertOk()
            ->json('products');

        $this->assertCount(30, $products);
        $this->assertNotContains('ST-OTRO', collect($products)->pluck('code')->all());

        $barcodeResult = $this->actingAs($user)
            ->getJson(route('stock.products.search', ['q' => 'BAR-30']))
            ->assertOk()
            ->json('products.0');

        $this->assertSame('ST-30', $barcodeResult['code']);
        $this->assertSame(30.0, (float) $barcodeResult['physical_stock']);
        $this->assertSame(7.0, (float) $barcodeResult['reserved_stock']);
        $this->assertSame(23.0, (float) $barcodeResult['available_stock']);

        $this->assertSame([], $this->actingAs($user)
            ->getJson(route('stock.products.search', ['q' => 'ST-OTRO']))
            ->assertOk()
            ->json('products'));
    }

    public function test_stock_adjustment_increases_stock_creates_row_and_movement(): void
    {
        [$business, $user] = $this->tenant('stock_manager', ['inventory']);
        $product = $this->product($business, 'Producto sin fila stock', 'NEW-STOCK');

        $response = $this->actingAs($user)
            ->postJson(route('stock.adjustments.store'), [
                'product_id' => $product->id,
                'type' => 'increase',
                'quantity' => 5,
                'note' => 'Conteo físico inicial',
            ])
            ->assertOk()
            ->json();

        $this->assertSame(0.0, (float) $response['previous_stock']);
        $this->assertSame(5.0, (float) $response['new_stock']);
        $this->assertSame(5.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $business->id,
            'product_id' => $product->id,
            'type' => 'entry',
            'note' => 'Conteo físico inicial',
        ]);
    }

    public function test_stock_adjustment_decrease_validates_available_stock_and_negative_policy(): void
    {
        [$business, $user, $branch] = $this->tenant('stock_manager', ['inventory']);
        $product = $this->product($business, 'Producto reservado', 'RES-1');
        ProductBranchStock::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => 10,
        ]);
        StockReservation::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'source_type' => 'manual_test',
            'source_id' => 1,
            'quantity' => 7,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('stock.adjustments.store'), [
                'product_id' => $product->id,
                'type' => 'decrease',
                'quantity' => 5,
                'note' => 'Merma por daño',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        TenantSetting::query()->where('business_id', $business->id)->update(['allow_negative_stock' => true]);

        $this->actingAs($user)
            ->postJson(route('stock.adjustments.store'), [
                'product_id' => $product->id,
                'type' => 'decrease',
                'quantity' => 5,
                'note' => 'Merma por daño',
            ])
            ->assertOk()
            ->assertJsonPath('product.physical_stock', 5);

        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $business->id,
            'product_id' => $product->id,
            'type' => 'exit',
            'quantity' => -5,
        ]);
    }

    public function test_stock_adjustment_validates_note_quantity_permission_and_tenant(): void
    {
        [$business, $user] = $this->tenant('stock_manager', ['inventory']);
        [$otherBusiness] = $this->tenant('stock_manager', ['inventory'], 'Other adjust tenant');
        $product = $this->product($business, 'Producto ajuste', 'ADJ-1');
        $otherProduct = $this->product($otherBusiness, 'Producto otro negocio', 'ADJ-OTHER');

        $this->actingAs($user)
            ->postJson(route('stock.adjustments.store'), [
                'product_id' => $product->id,
                'type' => 'increase',
                'quantity' => 0,
                'note' => 'Nota válida',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->actingAs($user)
            ->postJson(route('stock.adjustments.store'), [
                'product_id' => $product->id,
                'type' => 'increase',
                'quantity' => 1,
                'note' => 'No',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->actingAs($user)
            ->postJson(route('stock.adjustments.store'), [
                'product_id' => $otherProduct->id,
                'type' => 'increase',
                'quantity' => 1,
                'note' => 'Intento otro tenant',
            ])
            ->assertNotFound();

        $viewer = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'cashier',
            'is_active' => true,
            'current_branch_id' => BranchInventory::defaultBranch($business->id)->id,
        ]);
        Permissions::assignDirectPermissions($viewer, [Permissions::INVENTORY_VIEW]);

        $this->actingAs($viewer)
            ->postJson(route('stock.adjustments.store'), [
                'product_id' => $product->id,
                'type' => 'increase',
                'quantity' => 1,
                'note' => 'Sin permiso ajuste',
            ])
            ->assertForbidden();
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
