<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\CustomerAccountMovement;
use App\Models\ElectronicDocument;
use App\Models\FelReconciliationRequest;
use App\Models\PreSale;
use App\Models\PreSaleItem;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductPrice;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Fel\FelException;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RoutePreSaleInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_picked_pre_sale_converts_to_paid_receipt_using_picked_quantities_and_preserved_prices(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch, stock: 10, price: 100);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product, quantity: 4, pickedQuantity: 2, discount: 20);
        $this->openCashRegister($business, $branch, $admin);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('receipt', 'paid', 'cash'))
            ->assertRedirect(route('routes.pre-sales.show', $preSale))
            ->assertSessionHasNoErrors();

        $sale = Sale::query()->where('business_id', $business->id)->firstOrFail();
        $line = SaleItem::query()->where('sale_id', $sale->id)->firstOrFail();

        $this->assertSame('receipt', $sale->document_type);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(100.0, (float) $line->unit_price);
        $this->assertSame(190.0, (float) $sale->total);
        $this->assertSame(190.0, (float) $line->total);
        $this->assertSame(8.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseHas('stock_movements', ['business_id' => $business->id, 'product_id' => $product->id, 'type' => 'sale', 'quantity' => -2]);
        $this->assertDatabaseHas('cash_movements', ['business_id' => $business->id, 'reference_type' => 'sale', 'reference_id' => $sale->id, 'amount' => 190]);
        $this->assertSame(0, StockReservation::query()->where('source_id', $preSale->id)->where('status', 'active')->count());
        $this->assertSame(1, StockReservation::query()->where('source_id', $preSale->id)->where('status', 'consumed')->count());
        $this->assertSame(PreSale::STATUS_CONVERTED, $preSale->refresh()->status);
        $this->assertSame($sale->id, $preSale->converted_sale_id);
        $this->assertNotNull($preSale->converted_at);
        $this->assertNotNull($preSale->workDay->refresh()->completed_at);
    }

    public function test_credit_conversion_creates_receivable_without_cash_movement(): void
    {
        [$business, $admin, $branch] = $this->tenant(enableCreditSales: true);
        $product = $this->product($business, $branch, stock: 10, price: 75);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product, quantity: 2, pickedQuantity: 2);

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('receipt', 'credit'))
            ->assertSessionHasNoErrors();

        $sale = Sale::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertTrue($sale->is_credit_sale);
        $this->assertSame('unpaid', $sale->payment_status);
        $this->assertSame(150.0, (float) $sale->credit_balance);
        $this->assertDatabaseHas('customer_account_movements', ['business_id' => $business->id, 'sale_id' => $sale->id, 'type' => 'charge', 'amount' => 150]);
        $this->assertSame(0, CustomerAccountMovement::query()->where('business_id', $business->id)->where('type', 'payment')->count());
        $this->assertSame(0, Sale::query()->find($sale->id)->payments()->count());
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_fel_failure_rolls_back_sale_stock_cash_accounting_and_keeps_pre_sale_reservation(): void
    {
        [$business, $admin, $branch] = $this->tenant(allowInvoices: true);
        $this->felSettings($business, $branch);
        $product = $this->product($business, $branch, stock: 10, price: 100);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product, quantity: 2, pickedQuantity: 2);
        $this->openCashRegister($business, $branch, $admin);

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->once()->andThrow(new FelException('Digifact rechazó la factura.'));
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $this->actingAs($admin)
            ->from(route('routes.pre-sales.show', $preSale))
            ->post(route('routes.pre-sales.invoice', $preSale), $this->invoicePayload('invoice', 'paid', 'cash'))
            ->assertRedirect(route('routes.pre-sales.show', $preSale))
            ->assertSessionHasErrors('document_type');

        $this->assertSame(0, Sale::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, SaleItem::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, ElectronicDocument::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, StockMovement::query()->where('business_id', $business->id)->count());
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(PreSale::STATUS_PICKED, $preSale->refresh()->status);
        $this->assertSame(2.0, (float) StockReservation::query()->where('source_id', $preSale->id)->where('status', 'active')->sum('quantity'));
        $this->assertNull($preSale->converted_sale_id);
        $this->assertDatabaseHas('fel_reconciliation_requests', ['business_id' => $business->id, 'status' => 'pending']);
        $this->assertSame(1, FelReconciliationRequest::query()->where('business_id', $business->id)->count());
    }

    public function test_same_idempotency_key_replays_and_a_different_payload_conflicts_without_second_sale(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch, stock: 10, price: 100);
        $preSale = $this->pickedPreSale($business, $branch, $admin, $product);
        $this->openCashRegister($business, $branch, $admin);
        $payload = $this->invoicePayload('receipt', 'paid', 'cash', 'pre-sale-invoice-replay-key');

        $this->actingAs($admin)->post(route('routes.pre-sales.invoice', $preSale), $payload)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('routes.pre-sales.invoice', $preSale), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());

        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $preSale), [...$payload, 'payment_method' => 'card'])
            ->assertStatus(409);

        $this->assertSame(1, Sale::query()->where('business_id', $business->id)->count());
    }

    public function test_only_picked_pre_sales_can_be_invoiced_and_invoice_permission_is_required(): void
    {
        [$business, $admin, $branch] = $this->tenant();
        $product = $this->product($business, $branch);
        $picked = $this->pickedPreSale($business, $branch, $admin, $product);
        $submitted = $this->pickedPreSale($business, $branch, $admin, $product);
        $submitted->update(['status' => PreSale::STATUS_SUBMITTED, 'picked_at' => null, 'picked_by' => null]);

        $admin->roles()->detach();
        Permissions::assignDirectPermissions($admin, [Permissions::ROUTES_PRE_SALES_ADMIN_VIEW]);
        $this->actingAs($admin)->post(route('routes.pre-sales.invoice', $picked), $this->invoicePayload())->assertForbidden();

        Permissions::assignDirectPermissions($admin, [Permissions::ROUTES_PRE_SALES_ADMIN_VIEW, Permissions::ROUTES_PRE_SALES_INVOICE]);
        $this->actingAs($admin)
            ->post(route('routes.pre-sales.invoice', $submitted), $this->invoicePayload())
            ->assertSessionHasErrors('pre_sale');
    }

    private function tenant(bool $enableCreditSales = false, bool $allowInvoices = false): array
    {
        $business = Business::query()->create([
            'name' => 'Route invoice '.uniqid(),
            'slug' => 'route-invoice-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);

        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_branches' => true,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_receipts' => true,
            'allow_invoices' => $allowInvoices,
            'enable_credit_sales' => $enableCreditSales,
            'allow_negative_stock' => false,
            'route_pre_sale_invoicing_mode' => 'manual',
        ]);

        foreach (['routes', 'pos', 'inventory', 'branches', 'credits', 'cash_register', 'fel_gt'] as $module) {
            TenantModule::query()->create(['business_id' => $business->id, 'module' => $module, 'is_enabled' => true, 'enabled_at' => now()]);
        }

        $branch = BranchInventory::defaultBranchForBusiness($business);
        $admin = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'current_branch_id' => $branch->id,
            'is_active' => true,
            'is_super_admin' => false,
        ]);
        Permissions::assignRole($admin, 'owner');

        return [$business, $admin, $branch];
    }

    private function product(Business $business, Branch $branch, float $stock = 10, float $price = 100): Product
    {
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Producto preventa '.uniqid(),
            'code' => 'RPI-'.uniqid(),
            'cost_price' => 40,
            'sale_price' => $price,
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        ProductBranchStock::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock]);
        $priceType = PriceType::query()->create(['business_id' => $business->id, 'name' => 'General', 'is_default' => true, 'is_active' => true]);
        ProductPrice::query()->create(['business_id' => $business->id, 'product_id' => $product->id, 'price_type_id' => $priceType->id, 'price' => $price, 'is_active' => true]);

        return $product;
    }

    private function pickedPreSale(Business $business, Branch $branch, User $seller, Product $product, int $quantity = 1, int $pickedQuantity = 1, float $discount = 0): PreSale
    {
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente ruta '.uniqid(),
            'doc_type' => 'NIT',
            'doc_number' => (string) random_int(1000000, 9999999),
            'address' => 'Huehuetenango',
            'country' => 'GT',
            'name_locked' => true,
            'tax_lookup_verified_at' => now(),
        ]);
        $zone = RouteZone::query()->create(['business_id' => $business->id, 'branch_id' => $branch->id, 'assigned_user_id' => $seller->id, 'name' => 'Zona '.uniqid(), 'is_active' => true]);
        $workDay = RouteWorkDay::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_zone_id' => $zone->id,
            'seller_id' => $seller->id,
            'work_date' => today(),
            'status' => 'closed',
            'started_at' => now()->subHour(),
            'closed_at' => now(),
        ]);
        $preSale = PreSale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_work_day_id' => $workDay->id,
            'route_zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'seller_id' => $seller->id,
            'status' => PreSale::STATUS_PICKED,
            'subtotal' => $quantity * $product->sale_price,
            'discount_total' => $discount,
            'total' => ($quantity * $product->sale_price) - $discount,
            'picked_at' => now(),
            'picked_by' => $seller->id,
        ]);
        $item = PreSaleItem::query()->create([
            'business_id' => $business->id,
            'pre_sale_id' => $preSale->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'picked_quantity' => $pickedQuantity,
            'unit_price' => $product->sale_price,
            'original_price' => $product->sale_price,
            'manual_price' => false,
            'discount' => $discount,
            'total' => ($quantity * $product->sale_price) - $discount,
        ]);
        StockReservation::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'source_type' => 'pre_sale',
            'source_id' => $preSale->id,
            'source_item_id' => $item->id,
            'quantity' => $pickedQuantity,
            'status' => 'active',
            'created_by' => $seller->id,
        ]);

        return $preSale->load('workDay');
    }

    private function openCashRegister(Business $business, Branch $branch, User $user): void
    {
        CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);
    }

    private function felSettings(Business $business, Branch $branch): void
    {
        $settings = TenantFelSetting::query()->create([
            'business_id' => $business->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'enabled' => true,
            'issuer_tax_id' => '5888492',
            'username' => 'TESTUSER',
            'password' => 'secret',
            'test_base_url' => 'https://testnucgt.digifact.com/api',
            'establishment_code' => '1',
            'establishment_name' => 'Casa Matriz',
            'establishment_address' => 'Ciudad',
            'establishment_postal_code' => '01001',
            'establishment_municipality' => 'Guatemala',
            'establishment_department' => 'Guatemala',
            'establishment_country' => 'GT',
            'affiliate_type' => 'GEN',
        ]);
        TenantFelPhrase::query()->create([
            'business_id' => $business->id,
            'tenant_fel_setting_id' => $settings->id,
            'data_identifier' => '1',
            'phrase_type' => '1',
            'scenario_code' => '2',
            'type_data' => '1',
            'type_value' => '1',
            'scenario_data' => '1',
            'scenario_value' => '2',
        ]);
        $branch->update([
            'fel_establishment_code' => '1',
            'fel_establishment_name' => 'Casa Matriz',
            'fel_address' => 'Ciudad',
            'fel_postal_code' => '01001',
            'fel_municipality' => 'Guatemala',
            'fel_department' => 'Guatemala',
            'fel_country' => 'GT',
        ]);
    }

    private function invoicePayload(string $documentType = 'receipt', string $paymentCondition = 'paid', ?string $paymentMethod = 'cash', ?string $key = null): array
    {
        return [
            'idempotency_key' => $key ?: 'route-pre-sale-invoice-'.str_replace('.', '-', uniqid('', true)),
            'document_type' => $documentType,
            'payment_condition' => $paymentCondition,
            'payment_method' => $paymentMethod,
            'note' => 'Facturación de ruta',
        ];
    }
}
