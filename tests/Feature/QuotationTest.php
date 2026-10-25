<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PriceType;
use App\Models\Quotation;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::syncDefaults();
    }

    public function test_creating_a_quotation_snapshots_customer_and_never_changes_stock(): void
    {
        [$business, $user, $product] = $this->tenant();
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente fiscal',
            'commercial_name' => 'Comercial Cliente',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'address' => 'Zona 1',
            'address_reference' => 'Frente al mercado',
            'country' => 'GT',
            'phone' => '55550000',
        ]);
        $originalStock = $product->stock;

        $this->actingAs($user)
            ->post(route('quotations.store'), $this->payload($product, $customer->id, 100))
            ->assertRedirect();

        $quotation = Quotation::query()->with('items')->sole();
        $this->assertSame('COT-000001', $quotation->visible_number);
        $this->assertSame('Comercial Cliente', $quotation->customer_commercial_name);
        $this->assertSame('Frente al mercado', $quotation->customer_address_reference);
        $this->assertSame(100, $quotation->items->sole()->quantity);
        $this->assertSame('1000.00', $quotation->total);
        $this->assertSame($originalStock, $product->fresh()->stock);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_register_sessions', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('customer_credit_accounts', 0);
        $this->assertDatabaseCount('electronic_documents', 0);

        $this->actingAs($user)
            ->post(route('quotations.store'), $this->payload($product, $customer->id, 100))
            ->assertRedirect();

        $this->assertSame(2, Quotation::query()->where('business_id', $business->id)->count());
        $this->assertSame($originalStock, $product->fresh()->stock);
        $this->assertSame(0, StockReservation::query()->count());
    }

    public function test_quotations_have_business_scoped_counters_and_can_be_cancelled_without_stock_effects(): void
    {
        [$business, $user, $product] = $this->tenant();
        [$otherBusiness, $otherUser, $otherProduct] = $this->tenant();

        $this->actingAs($user)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $quotation = Quotation::query()->sole();
        $this->actingAs($otherUser)->post(route('quotations.store'), $this->payload($otherProduct))->assertRedirect();

        $this->assertSame(1, $quotation->business_number);
        $this->assertSame(1, Quotation::query()->where('business_id', $otherBusiness->id)->sole()->business_number);
        $this->actingAs($user)
            ->post(route('quotations.cancel', $quotation), ['cancellation_reason' => 'El cliente desistió'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Quotation::CANCELLED, $quotation->fresh()->status);
        $this->assertSame(1, $quotation->fresh()->items()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame($business->id, $quotation->fresh()->business_id);
    }

    public function test_print_routes_are_available_in_ticket_and_document_formats_and_are_tenant_scoped(): void
    {
        [$business, $user, $product] = $this->tenant();
        $this->actingAs($user)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $quotation = Quotation::query()->sole();

        $this->actingAs($user)->get(route('quotations.print', ['quotation' => $quotation, 'format' => 'ticket']))
            ->assertOk()
            ->assertSee('Cotización sujeta a disponibilidad de existencias')
            ->assertSee('no constituye factura ni comprobante fiscal');
        $this->actingAs($user)->get(route('quotations.print', ['quotation' => $quotation, 'format' => 'document']))
            ->assertOk()
            ->assertSee('Válida hasta');

        [, $otherUser] = $this->tenant();
        $this->actingAs($otherUser)->get(route('quotations.show', $quotation))->assertNotFound();
    }

    public function test_valid_until_is_required_and_quantities_are_integer(): void
    {
        [, $user, $product] = $this->tenant();

        TenantModule::query()->create(['business_id' => $user->business_id, 'module' => 'discounts', 'is_enabled' => true, 'enabled_at' => now()]);
        $this->actingAs($user)->post(route('quotations.store'), array_replace_recursive($this->payload($product), [
            'valid_until' => null,
            'items' => [['product_id' => $product->id, 'quantity' => 1.5, 'unit_price' => 10]],
        ]))->assertSessionHasErrors(['valid_until', 'items.0.quantity']);

        $this->actingAs($user)->post(route('quotations.store'), array_replace_recursive($this->payload($product), [
            'valid_until' => now(tenantTimezone())->subDay()->toDateString(),
        ]))->assertSessionHasErrors('valid_until');

        $this->actingAs($user)->post(route('quotations.store'), array_replace_recursive($this->payload($product), [
            'discount' => ['type' => 'percent', 'value' => 100, 'reason' => 'Inválido'],
        ]))->assertSessionHasErrors('discount.value');
    }

    public function test_tenant_cannot_view_print_or_cancel_another_tenants_quotation(): void
    {
        [, $user, $product] = $this->tenant();
        [$otherBusiness, $otherUser, $otherProduct] = $this->tenant();
        $this->actingAs($otherUser)->post(route('quotations.store'), $this->payload($otherProduct))->assertRedirect();
        $otherQuotation = Quotation::query()->where('business_id', $otherBusiness->id)->sole();

        $this->actingAs($user)->get(route('quotations.show', $otherQuotation))->assertNotFound();
        $this->actingAs($user)->get(route('quotations.print', ['quotation' => $otherQuotation, 'format' => 'ticket']))->assertNotFound();
        $this->actingAs($user)->post(route('quotations.cancel', $otherQuotation), ['cancellation_reason' => 'Manipulada'])->assertNotFound();
        $this->assertSame(Quotation::OPEN, $otherQuotation->fresh()->status);
    }

    public function test_server_ignores_manipulated_non_manual_prices_and_rejects_foreign_commercial_ids(): void
    {
        [$business, $user, $product] = $this->tenant();
        [$otherBusiness, , $otherProduct] = $this->tenant();
        $otherCustomer = Customer::query()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Cliente ajeno',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'country' => 'GT',
        ]);

        $manipulated = $this->payload($product);
        $manipulated['items'][0]['unit_price'] = 0.01;
        $manipulated['total'] = 0.01;
        $this->actingAs($user)->post(route('quotations.store'), $manipulated)->assertRedirect();
        $quotation = Quotation::query()->where('business_id', $business->id)->sole();
        $this->assertSame('10.00', $quotation->total);
        $this->assertSame('10.00', $quotation->items()->sole()->unit_price);

        $this->actingAs($user)->post(route('quotations.store'), array_replace_recursive($this->payload($product), [
            'customer' => ['id' => $otherCustomer->id],
        ]))->assertSessionHasErrors('customer.id');
        $this->actingAs($user)->post(route('quotations.store'), array_replace_recursive($this->payload($otherProduct), [
            'items' => [['product_id' => $otherProduct->id, 'quantity' => 1, 'price_source' => 'price_list']],
        ]))->assertSessionHasErrors('items');

        $otherPriceType = PriceType::query()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Lista ajena',
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->actingAs($user)->post(route('quotations.store'), array_replace_recursive($this->payload($product), [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price_type_id' => $otherPriceType->id, 'price_source' => 'price_list']],
        ]))->assertSessionHasErrors('items');
    }

    public function test_cashier_can_create_view_and_print_but_cannot_cancel(): void
    {
        [$business, $owner, $product] = $this->tenant();
        $cashier = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'cashier',
            'is_active' => true,
            'all_branches_access' => true,
        ]);
        Permissions::assignRole($cashier, 'cashier');

        $this->actingAs($cashier)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $quotation = Quotation::query()->where('business_id', $business->id)->sole();
        $this->actingAs($cashier)->get(route('quotations.show', $quotation))->assertOk();
        $this->actingAs($cashier)->get(route('quotations.print', ['quotation' => $quotation, 'format' => 'document']))->assertOk();
        $this->actingAs($cashier)->post(route('quotations.cancel', $quotation), ['cancellation_reason' => 'No autorizado'])->assertForbidden();
        $this->assertSame(Quotation::OPEN, $quotation->fresh()->status);
    }

    public function test_database_enforces_business_scoped_quotation_numbers(): void
    {
        [$business, $user] = $this->tenant();
        $attributes = [
            'business_id' => $business->id,
            'business_number' => 42,
            'branch_id' => BranchInventory::defaultBranch($business->id)->id,
            'created_by' => $user->id,
            'status' => Quotation::OPEN,
            'issued_at' => now(),
            'valid_until' => now()->addDay()->toDateString(),
            'total' => 1,
        ];
        Quotation::query()->create($attributes);

        $this->expectException(QueryException::class);
        Quotation::query()->create($attributes);
    }

    public function test_users_without_quotation_create_permission_and_tenants_without_pos_module_are_rejected(): void
    {
        [$business, , $product] = $this->tenant();
        $stockManager = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'stock_manager',
            'is_active' => true,
            'all_branches_access' => true,
        ]);
        Permissions::assignRole($stockManager, 'stock_manager');
        $this->actingAs($stockManager)->post(route('quotations.store'), $this->payload($product))->assertForbidden();

        [, $withoutPosUser] = $this->tenant(false);
        $this->actingAs($withoutPosUser)->get(route('quotations.index'))->assertForbidden();
    }

    private function payload(Product $product, ?int $customerId = null, int $quantity = 1): array
    {
        return [
            'customer' => $customerId ? ['id' => $customerId] : ['name' => 'Cliente de mostrador', 'doc_type' => 'CF', 'doc_number' => 'CF'],
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => 10,
                'price_source' => 'price_list',
                'manual_price' => false,
            ]],
            'valid_until' => now(tenantTimezone())->addDay()->toDateString(),
            'note' => 'Cotización de prueba',
        ];
    }

    /** @return array{Business, User, Product} */
    private function tenant(bool $withPos = true): array
    {
        $business = Business::query()->create([
            'name' => 'Quotation '.uniqid(),
            'slug' => 'quotation-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_product_images' => false,
            'max_users' => 10,
            'use_branches' => false,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_manual_price' => false,
            'remember_last_customer_product_price' => false,
            'enable_credit_sales' => false,
            'allow_negative_stock' => false,
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);
        if ($withPos) {
            TenantModule::query()->create(['business_id' => $business->id, 'module' => 'pos', 'is_enabled' => true, 'enabled_at' => now()]);
        }
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'is_active' => true,
            'all_branches_access' => true,
        ]);
        Permissions::assignRole($user, 'owner');
        BranchInventory::defaultBranch($business->id);
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Producto cotizable',
            'code' => 'COT-'.uniqid(),
            'cost_price' => 5,
            'sale_price' => 10,
            'stock' => 5,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        return [$business, $user, $product];
    }
}
