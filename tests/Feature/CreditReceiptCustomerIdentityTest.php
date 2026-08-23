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

    public function test_credit_reservation_reuses_generic_final_consumer_for_document_variants(): void
    {
        [$business, $user, $product] = $this->creditTenant();
        $generic = Customer::getOrCreateGenericFinalConsumer($business);

        $this->storeCreditReceipt($user, $product, 'C/F', 'Consumidor Final');
        $this->storeCreditReceipt($user, $product, 'C F', '');

        $this->assertSame(1, Customer::query()->where('business_id', $business->id)->count());
        $this->assertSame([$generic->id], CreditReceipt::query()
            ->where('business_id', $business->id)
            ->pluck('customer_id')
            ->unique()
            ->values()
            ->all());
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

    private function storeCreditReceipt(User $user, Product $product, string $document, string $name): void
    {
        $this->actingAs($user)
            ->post(route('credits.receipts.store'), [
                'idempotency_key' => 'credit-identity-'.str_replace('.', '-', uniqid('', true)),
                'customer' => [
                    'name' => $name,
                    'doc_type' => $document,
                    'doc_number' => $document,
                ],
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
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
