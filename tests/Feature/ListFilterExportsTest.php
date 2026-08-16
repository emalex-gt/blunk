<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
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

class ListFilterExportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_purchases_filter_by_date_and_payment_method(): void
    {
        [$business, $user, $branch] = $this->tenant('purchases', ['purchases']);
        $supplier = Supplier::query()->create(['business_id' => $business->id, 'name' => 'Proveedor A']);
        $this->purchase($business, $branch, $user, $supplier, 100, 'cash', now()->subMonth());
        $this->purchase($business, $branch, $user, $supplier, 200, 'card', now());

        $this->actingAs($user)
            ->get(route('purchases.index', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
                'payment_method' => 'card',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Purchases/Index')
                ->where('purchases.total', 1)
                ->where('purchases.data.0.total', '200.00'));
    }

    public function test_purchases_export_requires_permission_and_is_branch_scoped(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant('purchases', ['purchases']);
        $supplier = Supplier::query()->create(['business_id' => $business->id, 'name' => 'Proveedor A']);
        $this->purchase($business, $branch, $user, $supplier, 100, 'cash', now());
        $this->purchase($business, $otherBranch, $user, $supplier, 900, 'cash', now());

        $this->actingAs($user)
            ->get(route('purchases.export', ['format' => 'excel']))
            ->assertOk();

        $cashier = $this->user($business, $branch, 'cashier');

        $this->actingAs($cashier)
            ->get(route('purchases.export', ['format' => 'excel']))
            ->assertForbidden();
    }

    public function test_purchase_store_saves_trimmed_supplier_invoice_number(): void
    {
        [$business, $user, $branch] = $this->tenant('purchases', ['purchases']);
        $supplier = Supplier::query()->create(['business_id' => $business->id, 'name' => 'Proveedor A']);
        $product = $this->product($business, 'Producto comprado');

        $this->actingAs($user)
            ->post(route('purchases.store'), [
                'supplier_id' => $supplier->id,
                'supplier_invoice_number' => '  FAC-2026-001  ',
                'payment_method' => 'card',
                'branch_id' => $branch->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 15],
                ],
            ])
            ->assertRedirect(route('purchases.index'));

        $this->assertDatabaseHas('purchases', [
            'business_id' => $business->id,
            'supplier_invoice_number' => 'FAC-2026-001',
        ]);
    }

    public function test_purchases_filter_by_supplier_invoice_number(): void
    {
        [$business, $user, $branch] = $this->tenant('purchases', ['purchases']);
        $supplier = Supplier::query()->create(['business_id' => $business->id, 'name' => 'Proveedor A']);
        $this->purchase($business, $branch, $user, $supplier, 100, 'cash', now(), 'FAC-VISIBLE');
        $this->purchase($business, $branch, $user, $supplier, 200, 'cash', now(), 'FAC-HIDDEN');

        $this->actingAs($user)
            ->get(route('purchases.index', ['supplier_invoice_number' => 'VISIBLE']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Purchases/Index')
                ->where('purchases.total', 1)
                ->where('purchases.data.0.supplier_invoice_number', 'FAC-VISIBLE'));
    }

    public function test_purchase_show_and_pdf_include_supplier_invoice_number_and_are_scoped(): void
    {
        [$business, $user, $branch] = $this->tenant('purchases', ['purchases']);
        $supplier = Supplier::query()->create(['business_id' => $business->id, 'name' => 'Proveedor A']);
        $product = $this->product($business, 'Producto PDF');
        $purchase = $this->purchase($business, $branch, $user, $supplier, 30, 'card', now(), 'FAC-PDF-1');
        $purchase->items()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_cost' => 15,
            'previous_cost' => 10,
            'new_average_cost' => 15,
            'total' => 30,
        ]);

        $this->actingAs($user)
            ->get(route('purchases.show', $purchase))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Purchases/Show')
                ->where('purchase.supplier_invoice_number', 'FAC-PDF-1'));

        $html = view('pdf.purchases.show', [
            'purchase' => $purchase->load(['business.tenantSetting', 'supplier', 'branch', 'createdBy', 'items.product']),
            'business' => $business,
            'tenantSetting' => $business->tenantSetting,
            'purchaseNumber' => format_purchase_number($purchase),
            'timezone' => tenantTimezone($business),
        ])->render();

        $this->assertStringContainsString('FAC-PDF-1', $html);
        $this->assertStringContainsString('Comprobante de compra', $html);
        $this->assertStringContainsString('Tenant export', $html);
        $this->assertStringContainsString('Dirección: Avenida PDF 1', $html);
        $this->assertStringContainsString('Teléfono: 5555-0001', $html);
        $this->assertStringNotContainsString('NIT/documento proveedor', $html);

        $this->actingAs($user)
            ->get(route('purchases.pdf', $purchase))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $cashier = $this->user($business, $branch, 'cashier');

        $this->actingAs($cashier)
            ->get(route('purchases.pdf', $purchase))
            ->assertForbidden();

        [$otherBusiness, $otherUser] = $this->tenant('purchases', ['purchases']);
        $this->assertNotSame($business->id, $otherBusiness->id);

        $this->actingAs($otherUser)
            ->get(route('purchases.pdf', $purchase))
            ->assertForbidden();
    }

    public function test_transfers_filter_by_origin_destination_and_product(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant('stock_manager', ['branches']);
        $product = $this->product($business, 'Producto filtro');
        $this->transfer($business, $branch, $otherBranch, $user, $product, 2);
        $otherProduct = $this->product($business, 'Producto oculto');
        $this->transfer($business, $otherBranch, $branch, $user, $otherProduct, 5);

        $this->actingAs($user)
            ->get(route('inventory.transfers.index', [
                'origin_branch_id' => $branch->id,
                'destination_branch_id' => $otherBranch->id,
                'product_search' => 'filtro',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Transfers/Index')
                ->where('transfers.total', 1));
    }

    public function test_transfers_export_requires_permission_and_is_branch_scoped(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant('stock_manager', ['branches']);
        $product = $this->product($business, 'Producto traslado');
        $this->transfer($business, $branch, $otherBranch, $user, $product, 2);

        $this->actingAs($user)
            ->get(route('inventory.transfers.export', ['format' => 'pdf']))
            ->assertOk();

        $cashier = $this->user($business, $branch, 'cashier');

        $this->actingAs($cashier)
            ->get(route('inventory.transfers.export', ['format' => 'pdf']))
            ->assertForbidden();
    }

    public function test_transfer_pdf_is_letter_internal_document_and_is_scoped(): void
    {
        [$business, $user, $branch, $otherBranch] = $this->tenant('stock_manager', ['branches']);
        $product = $this->product($business, 'Producto traslado PDF');
        $transfer = $this->transfer($business, $branch, $otherBranch, $user, $product, 4);

        $html = view('pdf.inventory-transfers.show', [
            'transfer' => $transfer->load(['business.tenantSetting', 'fromBranch', 'toBranch', 'createdBy', 'lines.product']),
            'business' => $business,
            'tenantSetting' => $business->tenantSetting,
            'company' => \App\Support\DocumentCompanyHeader::make($business, $branch, $business->tenantSetting),
            'timezone' => tenantTimezone($business),
        ])->render();

        $this->assertStringContainsString('Traslado de inventario', $html);
        $this->assertStringContainsString('Producto traslado PDF', $html);
        $this->assertStringContainsString('Tenant export', $html);
        $this->assertStringContainsString('Dirección: Avenida PDF 1', $html);
        $this->assertStringContainsString('Teléfono: 5555-0001', $html);
        $this->assertStringContainsString('Documento interno generado por Kodbli/BlunkStock', $html);
        $this->assertStringNotContainsString('NIT:', $html);

        $this->actingAs($user)
            ->get(route('inventory.transfers.pdf', $transfer))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $cashier = $this->user($business, $branch, 'cashier');

        $this->actingAs($cashier)
            ->get(route('inventory.transfers.pdf', $transfer))
            ->assertForbidden();

        [$otherBusiness, $otherUser] = $this->tenant('stock_manager', ['branches']);
        $this->assertNotSame($business->id, $otherBusiness->id);

        $this->actingAs($otherUser)
            ->get(route('inventory.transfers.pdf', $transfer))
            ->assertForbidden();
    }

    public function test_purchase_draft_source_preserves_supplier_invoice_number(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Purchases/Create.tsx'));

        $this->assertStringContainsString('supplier_invoice_number: string', $source);
        $this->assertStringContainsString('supplier_invoice_number: supplierInvoiceNumber', $source);
        $this->assertStringContainsString("setSupplierInvoiceNumber(draft.supplier_invoice_number ?? '')", $source);
        $this->assertStringContainsString('supplier_invoice_number: supplierInvoiceNumber.trim() || null', $source);
        $this->assertStringContainsString('Factura proveedor', $source);
    }

    public function test_sale_internal_receipt_shows_company_and_customer_name_only(): void
    {
        [$business, $user, $branch] = $this->tenant('owner', ['pos']);
        $product = $this->product($business, 'Producto ticket');
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente con NIT',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
            'country' => 'GT',
        ]);
        $sale = $this->sale($business, $branch, $user, $product, $customer);

        $this->actingAs($user)
            ->get(route('sales.receipt', $sale))
            ->assertOk()
            ->assertSee('Tenant export')
            ->assertSee('Dirección: Avenida PDF 1')
            ->assertSee('Teléfono: 5555-0001')
            ->assertSee('Cliente con NIT')
            ->assertDontSee('57289085')
            ->assertDontSee('NIT:');

        $sale->forceFill([
            'customer_id' => null,
            'customer_name' => 'CF',
            'customer_doc_type' => 'CF',
            'customer_doc_number' => 'CF',
        ])->save();

        $this->actingAs($user)
            ->get(route('sales.receipt', $sale))
            ->assertOk()
            ->assertSee('Consumidor final')
            ->assertDontSee('Cliente: CF')
            ->assertDontSee('NIT: CF')
            ->assertDontSee('Doc.: CF');
    }

    private function tenant(string $role, array $modules): array
    {
        $business = Business::query()->create([
            'name' => 'Tenant export',
            'country' => 'GT',
            'currency' => 'GTQ',
            'is_active' => true,
        ]);

        TenantSetting::query()->create([
            'business_id' => $business->id,
            'company_name' => 'Comercial PDF',
            'company_address' => 'Dirección tenant',
            'company_phone' => '5555-9999',
            'company_tax_id' => '9999999',
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
        $branch->update([
            'address' => 'Avenida PDF 1',
            'phone' => '5555-0001',
        ]);
        $otherBranch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'address' => 'Avenida PDF 2',
            'phone' => '5555-0002',
            'is_active' => true,
        ]);
        $user = $this->user($business, $branch, $role);

        return [$business, $user, $branch, $otherBranch];
    }

    private function user(Business $business, Branch $branch, string $role): User
    {
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => $role,
            'is_active' => true,
            'current_branch_id' => $branch->id,
        ]);
        Permissions::assignRole($user, $role);

        return $user;
    }

    private function product(Business $business, string $name): Product
    {
        return Product::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => uniqid('P-'),
            'cost_price' => 10,
            'sale_price' => 20,
            'stock' => 10,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function purchase(Business $business, Branch $branch, User $user, Supplier $supplier, float $total, string $method, $createdAt, ?string $supplierInvoiceNumber = null): Purchase
    {
        $purchase = Purchase::query()->create([
            'business_id' => $business->id,
            'business_number' => Purchase::query()->where('business_id', $business->id)->max('business_number') + 1,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'supplier_invoice_number' => $supplierInvoiceNumber,
            'status' => 'completed',
            'total' => $total,
            'payment_method' => $method,
            'paid_from_cash' => $method === 'cash',
            'created_by' => $user->id,
        ]);
        $purchase->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $purchase;
    }

    private function sale(Business $business, Branch $branch, User $user, Product $product, Customer $customer): Sale
    {
        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'business_number' => 1,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_doc_type' => $customer->doc_type,
            'customer_doc_number' => $customer->doc_number,
            'total' => 20,
            'payment_method' => 'cash',
            'document_type' => 'receipt',
            'payment_status' => 'paid',
            'amount_paid' => 20,
            'credit_balance' => 0,
            'status' => 'completed',
            'created_by' => $user->id,
        ]);

        $sale->items()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 20,
            'unit_cost' => 10,
            'total_cost' => 10,
            'profit_amount' => 10,
            'total' => 20,
        ]);
        $sale->payments()->create([
            'business_id' => $business->id,
            'method' => 'cash',
            'amount' => 20,
        ]);

        return $sale;
    }

    private function transfer(Business $business, Branch $from, Branch $to, User $user, Product $product, int $quantity): InventoryTransfer
    {
        $transfer = InventoryTransfer::query()->create([
            'business_id' => $business->id,
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'status' => 'completed',
            'created_by' => $user->id,
        ]);
        $transfer->lines()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);

        return $transfer;
    }
}
