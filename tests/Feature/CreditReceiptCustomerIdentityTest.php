<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CreditReceipt;
use App\Models\Customer;
use App\Models\CustomerCreditAccount;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\RouteVisit;
use App\Models\RouteWorkDay;
use App\Models\RouteZone;
use App\Models\RouteZoneCustomer;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\FinalConsumerAuditor;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CreditReceiptCustomerIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permissions::syncDefaults();
    }

    public function test_credit_reservation_rejects_generic_final_consumer_document_variants_without_side_effects(): void
    {
        [$business, $user, $product] = $this->creditTenant();
        $generic = Customer::getOrCreateGenericFinalConsumer($business);

        foreach ([['C/F', 'Consumidor Final'], ['C F', ''], ['CF', '  consumidor   final  ']] as [$document, $name]) {
            $this->actingAs($user)
                ->post(route('credits.receipts.store'), $this->creditReceiptPayload($product, $document, $name))
                ->assertRedirect()
                ->assertSessionHasErrors('customer.name');
        }

        $this->assertSame(1, Customer::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, CreditReceipt::query()->where('business_id', $business->id)->count());
        $this->assertTrue($generic->fresh()->isGenericFinalConsumer());
    }

    public function test_credit_reservation_keeps_personalized_final_consumer_separate(): void
    {
        [$business, $user, $product] = $this->creditTenant();
        $generic = Customer::getOrCreateGenericFinalConsumer($business);

        $this->storeCreditReceipt($user, $product, 'CF', 'Cliente Mostrador');

        $personalized = Customer::query()
            ->where('business_id', $business->id)
            ->where('name', 'Cliente Mostrador')
            ->sole();

        $this->assertNotSame($generic->id, $personalized->id);
        $this->assertSame('CF', $personalized->normalized_tax_id);
        $this->assertSame($personalized->id, CreditReceipt::query()->sole()->customer_id);
    }

    public function test_credit_reservation_reuses_existing_personalized_final_consumer_without_creating_a_duplicate(): void
    {
        [$business, $user, $product] = $this->creditTenant();
        $personalized = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Juan Pérez',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'country' => 'GT',
            'is_final_consumer' => true,
        ]);

        $this->storeCreditReceipt($user, $product, 'CF', 'Juan Pérez', $personalized->id);

        $this->assertSame(1, Customer::query()->where('business_id', $business->id)->count());
        $this->assertSame($personalized->id, CreditReceipt::query()->sole()->customer_id);
    }

    public function test_credit_reservation_normalizes_a_personalized_final_consumer_document_variant_to_cf(): void
    {
        [$business, $user, $product] = $this->creditTenant();

        $this->storeCreditReceipt($user, $product, 'C/F', 'Cliente CF histórico');

        $receipt = CreditReceipt::query()->sole();
        $this->assertSame('CF', $receipt->customer_doc_type);
        $this->assertSame('CF', $receipt->customer_doc_number);
        $this->assertSame('CF', $receipt->customer->doc_type);
        $this->assertSame('CF', $receipt->customer->doc_number);
    }

    public function test_credit_reservation_with_a_selected_generic_and_personalized_name_creates_a_separate_customer(): void
    {
        [$business, $user, $product] = $this->creditTenant();
        $generic = Customer::getOrCreateGenericFinalConsumer($business);

        $this->storeCreditReceipt($user, $product, 'CF', 'María López', $generic->id);

        $this->assertSame('Consumidor Final', $generic->fresh()->name);
        $this->assertSame(2, Customer::query()->where('business_id', $business->id)->count());
        $this->assertSame('María López', CreditReceipt::query()->sole()->customer->name);
    }

    public function test_credit_reservation_replays_a_personalized_final_consumer_request_without_duplicate_effects(): void
    {
        [$business, $user, $product] = $this->creditTenant();
        $payload = $this->creditReceiptPayload($product, 'CF', 'Cliente CF repetible', null, 'credit-cf-replay');

        $this->actingAs($user)->post(route('credits.receipts.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('credits.receipts.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Customer::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, CreditReceipt::query()->where('business_id', $business->id)->count());
    }

    public function test_credit_reservation_snapshots_and_prints_complete_customer_location_in_both_formats(): void
    {
        [$business, $user, $product] = $this->creditTenant();
        $payload = $this->creditReceiptPayload($product, 'CF', 'Cliente con ubicación');
        $payload['customer'] = [
            ...$payload['customer'],
            'address' => '5a Avenida 10-25 Zona 1, Guatemala, Guatemala',
            'address_reference' => 'Frente al parque',
            'municipality' => 'Guatemala',
            'department' => 'Guatemala',
            'phone' => '5555-0101',
        ];

        $this->actingAs($user)
            ->post(route('credits.receipts.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $receipt = CreditReceipt::query()->sole();
        $this->assertSame('5a Avenida 10-25 Zona 1, Guatemala, Guatemala', $receipt->customer_address);
        $this->assertSame('Frente al parque', $receipt->customer_address_reference);
        $this->assertSame('Guatemala', $receipt->customer_municipality);
        $this->assertSame('Guatemala', $receipt->customer_department);
        $this->assertSame('5555-0101', $receipt->customer_phone);

        $this->actingAs($user)
            ->get(route('credits.receipts.print', $receipt))
            ->assertOk()
            ->assertSee('Descuento')
            ->assertSee('0.00')
            ->assertSee('Dirección:</strong> 5a Avenida 10-25 Zona 1, Guatemala, Guatemala', false)
            ->assertDontSee('Guatemala, Guatemala, Guatemala, Guatemala', false)
            ->assertSee('Referencia de dirección:</strong> Frente al parque', false)
            ->assertSee('Teléfono:</strong> 5555-0101', false);

        $business->tenantSetting->update(['receipt_format' => 'document']);

        $this->actingAs($user)
            ->get(route('credits.receipts.print', $receipt))
            ->assertOk()
            ->assertSee('Descuento')
            ->assertSee('0.00')
            ->assertSee('SKU: '.$product->code)
            ->assertSee('Dirección:</strong> 5a Avenida 10-25 Zona 1, Guatemala, Guatemala', false)
            ->assertSee('Referencia de dirección:</strong> Frente al parque', false)
            ->assertSee('Teléfono:</strong> 5555-0101', false);
    }

    public function test_historical_credit_receipt_print_uses_customer_location_as_a_read_only_fallback(): void
    {
        [$business, $user] = $this->creditTenant();
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente histórico',
            'doc_type' => 'NIT',
            'doc_number' => '5489652',
            'address' => '6a Calle 1-20',
            'address_reference' => 'Casa azul',
            'municipality' => 'Mixco',
            'department' => 'Guatemala',
            'phone' => '5555-0202',
            'country' => 'GT',
        ]);
        $receipt = $this->existingCreditReceipt($business, 1);
        $receipt->update([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_doc_number' => $customer->doc_number,
            'customer_address' => null,
        ]);

        $this->actingAs($user)
            ->get(route('credits.receipts.print', $receipt))
            ->assertOk()
            ->assertSee('Dirección:</strong> 6a Calle 1-20, Mixco, Guatemala', false)
            ->assertSee('Referencia de dirección:</strong> Casa azul', false)
            ->assertSee('Teléfono:</strong> 5555-0202', false);

        $receipt->refresh();
        $this->assertNull($receipt->customer_address);
        $this->assertNull($receipt->customer_address_reference);
        $this->assertNull($receipt->customer_municipality);
        $this->assertNull($receipt->customer_department);
        $this->assertNull($receipt->customer_phone);
    }

    public function test_credit_reservation_reconciles_a_missing_counter_with_an_existing_receipt_number(): void
    {
        [$business, $user, $product] = $this->creditTenant();
        $this->existingCreditReceipt($business, 1);

        $this->storeCreditReceipt($user, $product, 'CF', 'Cliente CF correlativo');

        $this->assertSame([1, 2], CreditReceipt::query()->orderBy('receipt_number')->pluck('receipt_number')->all());
    }

    public function test_credit_reservation_reconciles_a_stale_counter_and_preserves_a_counter_ahead_of_existing_receipts(): void
    {
        [$business, $user, $product] = $this->creditTenant();
        $this->existingCreditReceipt($business, 4);
        DB::table('business_counters')->insert([
            'business_id' => $business->id,
            'counter_key' => 'credit_receipts',
            'current_number' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->storeCreditReceipt($user, $product, 'CF', 'Cliente CF contador atrasado');
        $this->assertSame(5, CreditReceipt::query()->max('receipt_number'));

        DB::table('business_counters')
            ->where('business_id', $business->id)
            ->where('counter_key', 'credit_receipts')
            ->update(['current_number' => 9]);

        $this->storeCreditReceipt($user, $product, 'CF', 'Cliente CF contador adelantado');
        $this->assertSame(10, CreditReceipt::query()->max('receipt_number'));
    }

    public function test_credit_reservation_reuses_normalized_real_nit_without_partial_unique_index(): void
    {
        [$business, $user, $product] = $this->creditTenant();
        DB::statement('DROP INDEX IF EXISTS customers_business_real_tax_id_unique');
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente con NIT',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
            'country' => 'GT',
        ]);

        $this->storeCreditReceipt($user, $product, '5728-9085', 'Cliente con NIT');

        $this->assertSame(1, Customer::query()->where('business_id', $business->id)->count());
        $this->assertSame($customer->id, CreditReceipt::query()->sole()->customer_id);
    }

    public function test_real_nit_unique_violation_is_translated_to_a_readable_validation_error(): void
    {
        [$business] = $this->creditTenant();
        DB::unprepared(<<<'SQL'
CREATE FUNCTION raise_customer_nit_unique_violation() RETURNS trigger AS $$
BEGIN
    IF NEW.normalized_tax_id = '99999999' THEN
        RAISE EXCEPTION 'simulated duplicate tax ID' USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER customers_simulate_tax_id_unique
BEFORE INSERT ON customers
FOR EACH ROW EXECUTE FUNCTION raise_customer_nit_unique_violation();
SQL);

        try {
            Customer::findOrCreateByNormalizedTaxId($business, [
                'name' => 'Cliente conflicto',
                'doc_type' => 'NIT',
                'doc_number' => '99999999',
                'country' => 'GT',
            ]);
            $this->fail('Expected a validation exception for the database unique violation.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Ya existe un cliente con este NIT.'], $exception->errors()['customer.doc_number']);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS customers_simulate_tax_id_unique ON customers');
            DB::unprepared('DROP FUNCTION IF EXISTS raise_customer_nit_unique_violation()');
        }
    }

    public function test_merge_blocks_when_generic_final_consumers_have_conflicting_credit_accounts(): void
    {
        [$business] = $this->creditTenant();
        [$canonical, $duplicate] = $this->duplicateGenericCustomers($business);
        CustomerCreditAccount::query()->create([
            'business_id' => $business->id,
            'customer_id' => $canonical->id,
            'current_balance' => 0,
        ]);
        CustomerCreditAccount::query()->create([
            'business_id' => $business->id,
            'customer_id' => $duplicate->id,
            'current_balance' => 0,
        ]);

        $this->assertMergeBlocked($business, 'customer_credit_accounts');
    }

    public function test_merge_blocks_when_route_zone_customer_records_would_collide(): void
    {
        [$business, $user] = $this->creditTenant();
        [$canonical, $duplicate] = $this->duplicateGenericCustomers($business);
        $branch = BranchInventory::defaultBranch($business->id);
        $zone = RouteZone::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'name' => 'Zona CF',
        ]);

        foreach ([$canonical, $duplicate] as $customer) {
            RouteZoneCustomer::query()->create([
                'business_id' => $business->id,
                'route_zone_id' => $zone->id,
                'customer_id' => $customer->id,
            ]);
        }

        $this->assertMergeBlocked($business, 'route_zone_customers');
    }

    public function test_merge_blocks_when_route_visits_would_collide_and_reports_credit_receipts_to_move(): void
    {
        [$business, $user, $product] = $this->creditTenant();
        [$canonical, $duplicate] = $this->duplicateGenericCustomers($business);
        $branch = BranchInventory::defaultBranch($business->id);
        $zone = RouteZone::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'name' => 'Zona visitas',
        ]);
        $workDay = RouteWorkDay::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'route_zone_id' => $zone->id,
            'seller_id' => $user->id,
            'work_date' => today(),
            'status' => 'open',
        ]);

        foreach ([$canonical, $duplicate] as $customer) {
            RouteVisit::query()->create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'route_work_day_id' => $workDay->id,
                'route_zone_id' => $zone->id,
                'customer_id' => $customer->id,
                'seller_id' => $user->id,
                'status' => 'pending',
            ]);
        }

        CreditReceipt::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $duplicate->id,
            'customer_name' => $duplicate->name,
            'customer_doc_type' => 'CF',
            'customer_doc_number' => 'CF',
            'receipt_number' => 1,
            'status' => 'pending',
            'subtotal' => 1,
            'discount_amount' => 0,
            'total' => 1,
            'pending_total' => 1,
        ]);

        $plan = app(FinalConsumerAuditor::class)->merge(['business' => $business->id]);

        $this->assertSame(1, $plan['credit_receipts_to_move']);
        $this->assertContains('route_visits', $plan['blocking_relations']);
        $this->expectException(RuntimeException::class);
        app(FinalConsumerAuditor::class)->merge(['business' => $business->id, 'confirm' => true]);
    }

    private function creditTenant(): array
    {
        $business = Business::query()->create([
            'name' => 'Créditos '.uniqid(),
            'slug' => 'creditos-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'max_users' => 10,
            'use_branches' => false,
            'products_shared_across_branches' => true,
            'allow_receipts' => true,
            'allow_invoices' => false,
            'enable_credit_reservations' => true,
        ]);
        foreach (['credits', 'pos'] as $module) {
            TenantModule::query()->create([
                'business_id' => $business->id,
                'module' => $module,
                'is_enabled' => true,
                'enabled_at' => now(),
            ]);
        }
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
        Permissions::assignRole($user, 'owner');

        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Producto crédito',
            'code' => 'CRED-'.uniqid(),
            'sale_price' => 10,
            'cost_price' => 5,
            'stock' => 10,
            'is_active' => true,
        ]);
        $branch = BranchInventory::defaultBranch($business->id);
        ProductBranchStock::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => 10,
        ]);

        return [$business, $user, $product];
    }

    private function storeCreditReceipt(User $user, Product $product, string $document, string $name, ?int $customerId = null): void
    {
        $this->actingAs($user)
            ->post(route('credits.receipts.store'), $this->creditReceiptPayload($product, $document, $name, $customerId))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    private function creditReceiptPayload(Product $product, string $document, string $name, ?int $customerId = null, ?string $idempotencyKey = null): array
    {
        return [
            'idempotency_key' => $idempotencyKey ?? 'credit-identity-'.str_replace('.', '-', uniqid('', true)),
            'customer' => array_filter([
                'id' => $customerId,
                'name' => $name,
                'doc_type' => $document,
                'doc_number' => $document,
            ], static fn ($value): bool => $value !== null),
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 10,
            ]],
        ];
    }

    private function existingCreditReceipt(Business $business, int $receiptNumber): CreditReceipt
    {
        $branch = BranchInventory::defaultBranch($business->id);
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente correlativo',
            'doc_type' => 'NIT',
            'doc_number' => '9000000'.$receiptNumber,
            'country' => 'GT',
        ]);

        return CreditReceipt::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_doc_type' => 'NIT',
            'customer_doc_number' => $customer->doc_number,
            'receipt_number' => $receiptNumber,
            'status' => 'pending',
            'subtotal' => 10,
            'discount_amount' => 0,
            'total' => 10,
            'pending_total' => 10,
        ]);
    }

    private function duplicateGenericCustomers(Business $business): array
    {
        return [
            Customer::query()->create([
                'business_id' => $business->id,
                'name' => 'Consumidor Final',
                'doc_type' => 'CF',
                'doc_number' => 'CF',
                'country' => 'GT',
            ]),
            Customer::query()->create([
                'business_id' => $business->id,
                'name' => 'Consumidor Final',
                'doc_type' => 'CF',
                'doc_number' => 'CF',
                'country' => 'GT',
            ]),
        ];
    }

    private function assertMergeBlocked(Business $business, string $relation): void
    {
        $plan = app(FinalConsumerAuditor::class)->merge(['business' => $business->id]);

        $this->assertContains($relation, $plan['blocking_relations']);
        $this->expectException(RuntimeException::class);
        app(FinalConsumerAuditor::class)->merge(['business' => $business->id, 'confirm' => true]);
    }
}
