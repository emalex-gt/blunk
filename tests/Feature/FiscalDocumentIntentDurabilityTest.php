<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\FelIncident;
use App\Models\FiscalDocumentIntent;
use App\Models\OperationIdempotencyKey;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Fel\FelProviderRejectedException;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Services\Fel\Providers\Digifact\DigifactReconciliationService;
use App\Services\Fiscal\FiscalDocumentIntentService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class FiscalDocumentIntentDurabilityTest extends TestCase
{
    // Unlike RefreshDatabase, DatabaseTruncation does not keep the fixture in
    // a wrapping transaction. A separate pgsql connection can therefore prove
    // what another PHP worker would observe while Digifact is still waiting.
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('blunk_test', DB::connection()->getDatabaseName());
        Permissions::syncDefaults();
    }

    protected function tearDown(): void
    {
        DB::purge('pgsql_fel_observer');
        Mockery::close();

        parent::tearDown();
    }

    public function test_pending_intent_is_visible_to_an_independent_postgresql_connection_before_digifact_returns(): void
    {
        [$business, $user, $product, $branch] = $this->context();
        $key = 'durable-intent-'.uniqid();
        $observer = $this->independentObserverConnection();
        $observed = false;

        Http::fake([
            '*v2/transform/nuc_json*' => function () use ($business, $user, $product, $branch, $key, $observer, &$observed) {
                $observed = true;

                $this->assertNotSame(DB::connection()->getPdo(), $observer->getPdo());
                $intent = $observer->table('fiscal_document_intents')
                    ->where('business_id', $business->id)
                    ->where('idempotency_key', $key)
                    ->first();

                $this->assertNotNull($intent);
                $this->assertSame(FiscalDocumentIntent::PENDING, $intent->status);
                $this->assertNotEmpty($intent->fiscal_payload);
                $this->assertNotEmpty($intent->operation_payload);
                $this->assertSame(1, $observer->table('operation_idempotency_keys')
                    ->where('business_id', $business->id)
                    ->where('branch_id', $branch->id)
                    ->where('user_id', $user->id)
                    ->where('operation_type', 'pos_sale')
                    ->where('idempotency_key', $key)
                    ->count());
                $this->assertSame(1, $observer->table('stock_reservations')
                    ->where('business_id', $business->id)
                    ->where('source_type', 'fiscal_document_intent')
                    ->where('source_id', $intent->id)
                    ->where('product_id', $product->id)
                    ->where('status', 'active')
                    ->count());
                $this->assertSame(0, $observer->table('sales')->where('business_id', $business->id)->count());
                $this->assertSame(0, $observer->table('sale_payments')->count());
                $this->assertSame(0, $observer->table('stock_movements')->count());
                $this->assertSame(0, $observer->table('cash_movements')->count());

                throw new ConnectionException('Simulated Digifact timeout after durable intent commit.');
            },
        ]);

        $this->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $this->invoicePayload($product, $key))
            ->assertRedirect(route('sales.create'))
            ->assertSessionHas('warning');

        $this->assertTrue($observed, 'The controlled Digifact request was not reached.');
        $this->assertDatabaseHas('fiscal_document_intents', [
            'business_id' => $business->id,
            'idempotency_key' => $key,
            'status' => FiscalDocumentIntent::UNKNOWN,
        ]);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_explicit_late_rejection_after_provisional_acceptance_preserves_sale_and_creates_a_critical_incident(): void
    {
        [$business, $user, $product, $branch] = $this->context();
        $session = CashRegisterSession::query()->where('business_id', $business->id)->sole();
        $intent = FiscalDocumentIntent::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'cash_register_session_id' => $session->id,
            'created_by' => $user->id,
            'country' => 'GT',
            'provider' => 'digifact',
            'environment' => 'test',
            'document_type' => 'invoice',
            'operation_type' => 'sale',
            'internal_reference' => 'PROVISIONAL-'.uniqid(),
            'idempotency_key' => 'provisional-'.uniqid(),
            'status' => FiscalDocumentIntent::UNKNOWN,
            'operation_payload' => $this->operationPayload($business, $branch, $user, $product, $session),
            'fiscal_payload' => ['Header' => ['DocType' => 'FACT']],
        ]);
        StockReservation::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'source_type' => 'fiscal_document_intent',
            'source_id' => $intent->id,
            'quantity' => 1,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $service = app(FiscalDocumentIntentService::class);
        $accepted = $service->acceptProvisionalCommercialFinalization($intent, $user->id);
        $sale = $accepted->finalizedSale()->firstOrFail();
        $this->assertSame('completed', $sale->status);

        $before = [
            'sales' => Sale::query()->count(),
            'items' => SaleItem::query()->count(),
            'payments' => SalePayment::query()->count(),
            'stock' => StockMovement::query()->count(),
            'cash' => CashMovement::query()->count(),
            'documents' => DB::table('electronic_documents')->count(),
        ];

        $invoice = Mockery::mock(DigifactInvoiceService::class);
        $invoice->shouldReceive('certifyIntent')->once()
            ->andThrow(new FelProviderRejectedException('Explicit provider rejection.', responsePayload: ['code' => 'REJECTED']));
        $accepted->update(['status' => FiscalDocumentIntent::PENDING]);
        $result = (new FiscalDocumentIntentService($invoice, Mockery::mock(DigifactReconciliationService::class)))
            ->certify($accepted->fresh());

        $this->assertSame(FiscalDocumentIntent::REJECTED, $result->status);
        $this->assertSame($sale->id, $result->finalized_sale_id);
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertDatabaseHas('fel_incidents', [
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'internal_reference' => $result->internal_reference,
            'type' => 'provisional_certification_rejected',
            'severity' => 'critical',
            'status' => 'open',
        ]);
        $this->assertSame($before['sales'], Sale::query()->count());
        $this->assertSame($before['items'], SaleItem::query()->count());
        $this->assertSame($before['payments'], SalePayment::query()->count());
        $this->assertSame($before['stock'], StockMovement::query()->count());
        $this->assertSame($before['cash'], CashMovement::query()->count());
        $this->assertSame($before['documents'], DB::table('electronic_documents')->count());
        $this->assertSame('consumed', StockReservation::query()->where('source_id', $intent->id)->sole()->status);
    }

    private function independentObserverConnection()
    {
        config(['database.connections.pgsql_fel_observer' => config('database.connections.pgsql')]);
        DB::purge('pgsql_fel_observer');
        $observer = DB::connection('pgsql_fel_observer');

        $this->assertSame('pgsql', $observer->getDriverName());
        $this->assertSame('blunk_test', $observer->getDatabaseName());

        return $observer;
    }

    private function context(): array
    {
        $business = Business::query()->create([
            'name' => 'FEL durability test',
            'slug' => 'fel-durability-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'max_users' => 10,
            'use_branches' => false,
            'products_shared_across_branches' => true,
            'pricing_scope' => 'global',
            'allow_manual_price' => false,
            'remember_last_customer_product_price' => false,
            'enable_credit_sales' => false,
            'enable_credit_reservations' => false,
            'allow_negative_stock' => false,
            'allow_receipts' => true,
            'allow_invoices' => true,
        ]);
        foreach (['pos', 'cash_register', 'fel_gt'] as $module) {
            TenantModule::query()->create([
                'business_id' => $business->id,
                'module' => $module,
                'is_enabled' => true,
                'enabled_at' => now(),
            ]);
        }
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'cashier',
            'is_active' => true,
            'all_branches_access' => true,
        ]);
        Permissions::assignRole($user, 'cashier');

        $branch = BranchInventory::defaultBranchForBusiness($business);
        $branch->update([
            'department' => 'Huehuetenango',
            'municipality' => 'Huehuetenango',
            'fel_establishment_code' => '1',
            'fel_establishment_name' => 'Casa Matriz',
            'fel_address' => 'Ciudad',
            'fel_postal_code' => '13001',
            'fel_municipality' => 'Huehuetenango',
            'fel_department' => 'Huehuetenango',
            'fel_country' => 'GT',
        ]);
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
            'establishment_postal_code' => '13001',
            'establishment_municipality' => 'Huehuetenango',
            'establishment_department' => 'Huehuetenango',
            'establishment_country' => 'GT',
            'affiliate_type' => 'GEN',
            'token' => 'cached-token',
            'token_expires_at' => now()->addHour(),
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
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Producto FEL',
            'code' => 'FEL-'.uniqid(),
            'cost_price' => 50,
            'sale_price' => 100,
            'stock' => 10,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        ProductBranchStock::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock' => 10,
        ]);
        CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);

        return [$business, $user, $product, $branch];
    }

    private function invoicePayload(Product $product, string $idempotencyKey): array
    {
        return [
            'idempotency_key' => $idempotencyKey,
            'document_type' => 'invoice',
            'customer' => [
                'name' => 'Consumidor Final',
                'doc_type' => 'CF',
                'doc_number' => 'CF',
                'country' => 'GT',
                'consumidor_final' => true,
            ],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ];
    }

    private function operationPayload(Business $business, $branch, User $user, Product $product, CashRegisterSession $session): array
    {
        return [
            'sale' => [
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'customer_name' => 'Consumidor Final',
                'customer_doc_type' => 'CF',
                'customer_doc_number' => 'CF',
                'customer_country' => 'GT',
                'subtotal_before_discount' => 100,
                'discount_amount' => 0,
                'total' => 100,
                'payment_method' => 'cash',
                'is_credit_sale' => false,
                'document_type' => 'invoice',
                'created_by' => $user->id,
            ],
            'items' => [[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => 100,
                'original_price' => 100,
                'unit_cost' => 50,
                'total_cost' => 50,
                'profit_amount' => 50,
                'total' => 100,
                'total_before_discount' => 100,
                'total_after_discount' => 100,
                'discount_amount' => 0,
            ]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
            'cash_register_session_id' => $session->id,
        ];
    }
}
