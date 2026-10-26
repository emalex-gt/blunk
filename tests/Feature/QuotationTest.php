<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashRegisterSession;
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

    public function test_duplicating_a_quotation_creates_a_new_open_commercial_snapshot_without_operational_effects(): void
    {
        [$business, $owner, $product] = $this->tenant();
        $originalCreator = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'is_active' => true,
            'all_branches_access' => true,
        ]);
        Permissions::assignRole($originalCreator, 'owner');
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Nombre fiscal original',
            'commercial_name' => 'Comercial original',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'address' => 'Dirección original',
            'address_reference' => 'Frente al mercado',
            'country' => 'GT',
        ]);

        $this->actingAs($originalCreator)
            ->post(route('quotations.store'), $this->payload($product, $customer->id))
            ->assertRedirect();
        $original = Quotation::query()->with('items')->sole();
        $original->update([
            'issued_at' => now(tenantTimezone($business))->subDays(15)->startOfDay(),
            'valid_until' => now(tenantTimezone($business))->toDateString(),
            'note' => 'Referencia comercial conservada',
            'discount_type' => 'fixed',
            'discount_value' => 1,
            'discount_amount' => 1,
            'discount_reason' => 'Condición comercial',
            'subtotal_before_discount' => 10,
            'total' => 9,
        ]);
        $original->items()->update([
            'discount_amount' => 1,
            'total_before_discount' => 10,
            'total_after_discount' => 9,
            'total' => 9,
        ]);
        $expectedValidUntil = now(tenantTimezone($business))->startOfDay()->addDays(15)->toDateString();
        $originalStock = $product->fresh()->stock;

        $this->actingAs($owner)
            ->post(route('quotations.duplicate', $original))
            ->assertRedirect();

        $duplicate = Quotation::query()->with('items')->whereKeyNot($original->id)->sole();
        $this->assertSame(2, $duplicate->business_number);
        $this->assertSame(Quotation::OPEN, $duplicate->status);
        $this->assertSame($owner->id, $duplicate->created_by);
        $this->assertSame($expectedValidUntil, $duplicate->valid_until->toDateString());
        $this->assertSame($original->customer_name, $duplicate->customer_name);
        $this->assertSame($original->customer_commercial_name, $duplicate->customer_commercial_name);
        $this->assertSame($original->customer_address_reference, $duplicate->customer_address_reference);
        $this->assertSame($original->note, $duplicate->note);
        $this->assertSame('1.00', $duplicate->discount_amount);
        $this->assertSame($original->fresh()->items()->sole()->only(['product_id', 'quantity', 'unit_price', 'discount_amount', 'total']), $duplicate->items->sole()->only(['product_id', 'quantity', 'unit_price', 'discount_amount', 'total']));
        $this->assertNull($duplicate->cancelled_at);
        $this->assertNull($duplicate->cancelled_by);
        $this->assertNull($duplicate->converted_at);
        $this->assertSame(Quotation::OPEN, $original->fresh()->status);
        $this->assertSame($originalCreator->id, $original->fresh()->created_by);
        $this->assertSame($originalStock, $product->fresh()->stock);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_cancelled_and_converted_quotations_can_be_duplicated_without_copying_their_state(): void
    {
        [$business, $user, $product] = $this->tenant();
        $this->actingAs($user)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $cancelled = Quotation::query()->sole();
        $cancelled->update([
            'status' => Quotation::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancellation_reason' => 'No aceptada',
        ]);

        $this->actingAs($user)->post(route('quotations.duplicate', $cancelled))->assertRedirect();
        $cancelledCopy = Quotation::query()->whereKeyNot($cancelled->id)->sole();
        $this->assertSame(Quotation::OPEN, $cancelledCopy->status);
        $this->assertNull($cancelledCopy->cancelled_at);
        $this->assertSame(Quotation::CANCELLED, $cancelled->fresh()->status);

        $this->actingAs($user)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $converted = Quotation::query()->whereNotIn('id', [$cancelled->id, $cancelledCopy->id])->sole();
        $converted->update(['status' => Quotation::CONVERTED, 'converted_at' => now()]);

        $this->actingAs($user)->post(route('quotations.duplicate', $converted))->assertRedirect();
        $convertedCopy = Quotation::query()->whereNotIn('id', [$cancelled->id, $cancelledCopy->id, $converted->id])->sole();
        $this->assertSame(Quotation::OPEN, $convertedCopy->status);
        $this->assertNull($convertedCopy->converted_at);
        $this->assertSame(Quotation::CONVERTED, $converted->fresh()->status);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_quotation_duplication_is_tenant_scoped_and_requires_create_permission(): void
    {
        [$business, $owner, $product] = $this->tenant();
        [$otherBusiness, $otherOwner, $otherProduct] = $this->tenant();
        $this->actingAs($otherOwner)->post(route('quotations.store'), $this->payload($otherProduct))->assertRedirect();
        $foreignQuotation = Quotation::query()->where('business_id', $otherBusiness->id)->sole();
        $this->actingAs($owner)->post(route('quotations.duplicate', $foreignQuotation))->assertNotFound();

        $this->actingAs($owner)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $ownQuotation = Quotation::query()->where('business_id', $business->id)->sole();
        $stockManager = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'stock_manager',
            'is_active' => true,
            'all_branches_access' => true,
        ]);
        Permissions::assignRole($stockManager, 'stock_manager');

        $this->actingAs($stockManager)->post(route('quotations.duplicate', $ownQuotation))->assertForbidden();
        $this->assertSame(1, Quotation::query()->where('business_id', $business->id)->count());
    }

    public function test_print_routes_are_available_in_ticket_and_document_formats_and_are_tenant_scoped(): void
    {
        [$business, $user, $product] = $this->tenant();
        BranchInventory::defaultBranch($business->id)->update(['name' => 'Sucursal Central', 'code' => 'CENTRAL']);
        $this->actingAs($user)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $quotation = Quotation::query()->sole();

        $this->actingAs($user)->get(route('quotations.print', ['quotation' => $quotation, 'format' => 'ticket']))
            ->assertOk()
            ->assertSee('Cotización sujeta a disponibilidad de existencias')
            ->assertSee('no constituye factura ni comprobante fiscal')
            ->assertSee('Sucursal: Sucursal Central (CENTRAL)');
        $this->actingAs($user)->get(route('quotations.print', ['quotation' => $quotation, 'format' => 'document']))
            ->assertOk()
            ->assertSee('Válida hasta')
            ->assertSee('Sucursal: Sucursal Central (CENTRAL)');

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

    public function test_opening_a_quotation_conversion_does_not_change_it_and_expired_requires_confirmation(): void
    {
        [$business, $user, $product] = $this->tenant();
        $this->actingAs($user)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $quotation = Quotation::query()->sole();

        $this->actingAs($user)
            ->post(route('quotations.convert', $quotation))
            ->assertRedirect(route('sales.create', ['quotation_id' => $quotation->id, 'confirm_expired' => false]));
        $this->assertSame(Quotation::OPEN, $quotation->fresh()->status);

        $quotation->update(['valid_until' => now(tenantTimezone($business))->subDay()->toDateString()]);
        $this->actingAs($user)
            ->post(route('quotations.convert', $quotation))
            ->assertSessionHasErrors('quotation');
        $this->actingAs($user)
            ->post(route('quotations.convert', $quotation), ['confirm_expired' => true])
            ->assertRedirect(route('sales.create', ['quotation_id' => $quotation->id, 'confirm_expired' => true]));
        $this->assertSame(Quotation::OPEN, $quotation->fresh()->status);
    }

    public function test_sale_checkout_reconstructs_quoted_price_and_marks_quotation_converted_only_after_success(): void
    {
        [$business, $user, $product] = $this->tenant();
        $this->actingAs($user)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $quotation = Quotation::query()->with('items')->sole();
        $product->update(['sale_price' => 25]);
        BranchInventory::adjust($product->fresh(), BranchInventory::defaultBranch($business->id)->id, 5);
        $this->openCash($business, $user);

        $salePayload = [
            'idempotency_key' => 'quotation-sale-'.uniqid(),
            'branch_id' => $quotation->branch_id,
            'quotation_id' => $quotation->id,
            'quotation_expired_confirmed' => false,
            'document_type' => 'receipt',
            'payment_condition' => 'paid',
            'customer' => ['name' => 'Cliente de mostrador', 'doc_type' => 'CF', 'doc_number' => 'CF'],
            'payments' => [['method' => 'cash', 'amount' => 10]],
            'items' => [[
                'product_id' => $product->id,
                'quotation_item_id' => $quotation->items->sole()->id,
                'quantity' => 1,
                'unit_price' => 10,
                'price_source' => 'price_list',
                'manual_price' => false,
            ]],
        ];

        $this->actingAs($user)->post(route('sales.store'), $salePayload)->assertSessionHasNoErrors();
        $sale = \App\Models\Sale::query()->sole();
        $this->assertSame($quotation->id, $sale->quotation_id);
        $this->assertSame('10.00', $sale->items()->sole()->unit_price);
        $this->assertSame(Quotation::CONVERTED, $quotation->fresh()->status);
        $this->assertNotNull($quotation->fresh()->converted_at);
        $this->assertSame($sale->id, $quotation->fresh()->convertedSale()->sole()->id);
        $this->assertSame($quotation->id, $sale->fresh()->quotation()->sole()->id);

        $salePayload['idempotency_key'] = 'quotation-sale-second-'.uniqid();
        $this->actingAs($user)->post(route('sales.store'), $salePayload)->assertSessionHasErrors('quotation_id');
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_manual_quotation_id_from_another_tenant_or_cancelled_quotation_is_rejected(): void
    {
        [$business, $user, $product] = $this->tenant();
        [, $otherUser, $otherProduct] = $this->tenant();
        $this->actingAs($otherUser)->post(route('quotations.store'), $this->payload($otherProduct))->assertRedirect();
        $foreign = Quotation::query()->where('business_id', '!=', $business->id)->sole();
        BranchInventory::adjust($product->fresh(), BranchInventory::defaultBranch($business->id)->id, 5);
        $this->openCash($business, $user);

        $payload = [
            'idempotency_key' => 'foreign-quote-'.uniqid(), 'quotation_id' => $foreign->id,
            'document_type' => 'receipt', 'payment_condition' => 'paid',
            'customer' => ['doc_type' => 'CF', 'doc_number' => 'CF', 'name' => 'Consumidor Final'],
            'payments' => [['method' => 'cash', 'amount' => 10]],
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10, 'price_source' => 'price_list']],
        ];
        $this->actingAs($user)->post(route('sales.store'), $payload)->assertNotFound();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_changing_a_quoted_price_uses_the_normal_manual_price_policy(): void
    {
        [$business, $user, $product] = $this->tenant();
        $this->actingAs($user)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $quotation = Quotation::query()->sole();
        BranchInventory::adjust($product->fresh(), BranchInventory::defaultBranch($business->id)->id, 5);
        $this->openCash($business, $user);

        $this->actingAs($user)->post(route('sales.store'), [
            'idempotency_key' => 'quoted-price-change-'.uniqid(),
            'quotation_id' => $quotation->id,
            'document_type' => 'receipt',
            'payment_condition' => 'paid',
            'customer' => ['doc_type' => 'CF', 'doc_number' => 'CF', 'name' => 'Consumidor Final'],
            'payments' => [['method' => 'cash', 'amount' => 9]],
            'items' => [[
                'product_id' => $product->id,
                'quotation_item_id' => $quotation->items()->sole()->id,
                'quantity' => 1,
                'unit_price' => 9,
                'price_source' => 'manual',
                'manual_price' => true,
            ]],
        ])->assertForbidden();

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(Quotation::OPEN, $quotation->fresh()->status);
    }

    public function test_removing_another_quoted_line_keeps_the_matching_line_price(): void
    {
        [$business, $user, $product] = $this->tenant();
        $this->actingAs($user)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $quotation = Quotation::query()->with('items')->sole();
        $quotedA = $quotation->items->sole();
        $productB = $this->additionalProduct($business, 'Producto B', 30);
        $this->appendQuotedItem($quotation, $productB, 1, 12);
        $product->update(['sale_price' => 25]);
        BranchInventory::adjust($product->fresh(), BranchInventory::defaultBranch($business->id)->id, 5);
        $this->openCash($business, $user);

        $this->actingAs($user)->post(route('sales.store'), $this->salePayload($quotation, [[
            'product_id' => $product->id,
            'quotation_item_id' => $quotedA->id,
            'quantity' => 1,
            'unit_price' => 10,
        ]], 10))->assertSessionHasNoErrors();

        $this->assertSame('10.00', \App\Models\Sale::query()->sole()->items()->sole()->unit_price);
    }

    public function test_adding_a_non_quoted_line_keeps_each_matching_quoted_line_price(): void
    {
        [$business, $user, $product] = $this->tenant();
        $this->actingAs($user)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $quotation = Quotation::query()->with('items')->sole();
        $quotedA = $quotation->items->sole();
        $productC = $this->additionalProduct($business, 'Producto C', 20);
        $product->update(['sale_price' => 25]);
        BranchInventory::adjust($product->fresh(), BranchInventory::defaultBranch($business->id)->id, 5);
        BranchInventory::adjust($productC, BranchInventory::defaultBranch($business->id)->id, 5);
        $this->openCash($business, $user);

        $this->actingAs($user)->post(route('sales.store'), $this->salePayload($quotation, [
            ['product_id' => $product->id, 'quotation_item_id' => $quotedA->id, 'quantity' => 1, 'unit_price' => 10],
            ['product_id' => $productC->id, 'quantity' => 1, 'unit_price' => 20],
        ], 30))->assertSessionHasNoErrors();

        $prices = \App\Models\Sale::query()->sole()->items()->pluck('unit_price', 'product_id');
        $this->assertSame('10.00', $prices[$product->id]);
        $this->assertSame('20.00', $prices[$productC->id]);
    }

    public function test_changing_a_quoted_quantity_does_not_reuse_the_quoted_price(): void
    {
        [$business, $user, $product] = $this->tenant();
        $this->actingAs($user)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $quotation = Quotation::query()->with('items')->sole();
        $quotedA = $quotation->items->sole();
        $product->update(['sale_price' => 25]);
        BranchInventory::adjust($product->fresh(), BranchInventory::defaultBranch($business->id)->id, 5);
        $this->openCash($business, $user);

        $this->actingAs($user)->post(route('sales.store'), $this->salePayload($quotation, [[
            'product_id' => $product->id,
            'quotation_item_id' => $quotedA->id,
            'quantity' => 2,
            'unit_price' => 10,
        ]], 50))->assertSessionHasNoErrors();

        $item = \App\Models\Sale::query()->sole()->items()->sole();
        $this->assertSame(2, $item->quantity);
        $this->assertSame('25.00', $item->unit_price);
    }

    public function test_duplicate_product_quotation_lines_are_trusted_by_their_own_snapshot_id(): void
    {
        [$business, $user, $product] = $this->tenant();
        $this->actingAs($user)->post(route('quotations.store'), $this->payload($product))->assertRedirect();
        $quotation = Quotation::query()->with('items')->sole();
        $first = $quotation->items->sole();
        $second = $this->appendQuotedItem($quotation, $product, 1, 8);
        $product->update(['sale_price' => 25]);
        BranchInventory::adjust($product->fresh(), BranchInventory::defaultBranch($business->id)->id, 5);
        $this->openCash($business, $user);

        $this->actingAs($user)->post(route('sales.store'), $this->salePayload($quotation, [
            ['product_id' => $product->id, 'quotation_item_id' => $first->id, 'quantity' => 1, 'unit_price' => 10],
            ['product_id' => $product->id, 'quotation_item_id' => $second->id, 'quantity' => 1, 'unit_price' => 8],
        ], 18))->assertSessionHasNoErrors();

        $this->assertSame(['8.00', '10.00'], \App\Models\Sale::query()->sole()->items()->orderBy('unit_price')->pluck('unit_price')->all());
    }

    private function salePayload(Quotation $quotation, array $items, int|float $total): array
    {
        return [
            'idempotency_key' => 'quotation-line-'.uniqid(),
            'quotation_id' => $quotation->id,
            'document_type' => 'receipt',
            'payment_condition' => 'paid',
            'customer' => ['doc_type' => 'CF', 'doc_number' => 'CF', 'name' => 'Consumidor Final'],
            'payments' => [['method' => 'cash', 'amount' => $total]],
            'items' => array_map(fn (array $item) => [
                ...$item,
                'price_source' => 'price_list',
                'manual_price' => false,
            ], $items),
        ];
    }

    private function additionalProduct(Business $business, string $name, int|float $salePrice): Product
    {
        return Product::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => 'COT-'.uniqid(),
            'cost_price' => 5,
            'sale_price' => $salePrice,
            'stock' => 5,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function appendQuotedItem(Quotation $quotation, Product $product, int $quantity, int|float $unitPrice): \App\Models\QuotationItem
    {
        return $quotation->items()->create([
            'business_id' => $quotation->business_id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->code,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'original_price' => $unitPrice,
            'price_source' => 'price_list',
            'manual_price' => false,
            'discount_amount' => 0,
            'total_before_discount' => $quantity * $unitPrice,
            'total_after_discount' => $quantity * $unitPrice,
            'total' => $quantity * $unitPrice,
        ]);
    }

    private function openCash(Business $business, User $user): void
    {
        CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => BranchInventory::defaultBranch($business->id)->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'opened_at' => now(),
        ]);
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
