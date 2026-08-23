<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\OperationIdempotencyKey;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductPrice;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use App\Support\IdempotencyService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaleDuplicateIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        Permissions::syncDefaults();
    }

    public function test_first_sale_request_with_idempotency_key_creates_sale(): void
    {
        [$business, $user] = $this->tenant();
        $product = $this->product($business, stock: 5, salePrice: 100);
        $this->openCashRegister($business, $user);

        $this->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $this->salePayload($product, 'idem-sale-1'))
            ->assertRedirect(route('sales.create'))
            ->assertSessionHasNoErrors();

        $sale = Sale::query()->firstOrFail();

        $this->assertSame(1, Sale::query()->count());
        $this->assertDatabaseHas('operation_idempotency_keys', [
            'business_id' => $business->id,
            'operation_type' => 'pos_sale',
            'idempotency_key' => 'idem-sale-1',
            'status' => 'completed',
            'result_type' => 'sale',
            'result_id' => $sale->id,
        ]);
    }

    public function test_repeated_same_idempotency_key_returns_existing_sale_without_stock_or_cash_duplicate(): void
    {
        [$business, $user] = $this->tenant();
        $product = $this->product($business, stock: 5, salePrice: 100);
        $this->openCashRegister($business, $user);
        $payload = $this->salePayload($product, 'idem-sale-repeat');

        $this->actingAs($user)->from(route('sales.create'))->post(route('sales.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($user)->from(route('sales.create'))->post(route('sales.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(1, SaleItem::query()->count());
        $this->assertSame(1, SalePayment::query()->count());
        $this->assertSame(1, CashMovement::query()->where('type', 'sale_cash')->count());
        $this->assertSame(1, StockMovement::query()->where('type', 'sale')->count());
        $this->assertSame(4.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
    }

    public function test_same_idempotency_key_with_different_payload_returns_conflict(): void
    {
        [$business, $user] = $this->tenant();
        $product = $this->product($business, stock: 5, salePrice: 100);
        $this->openCashRegister($business, $user);
        $payload = $this->salePayload($product, 'idem-sale-conflict');

        $this->actingAs($user)->from(route('sales.create'))->post(route('sales.store'), $payload)->assertSessionHasNoErrors();

        $changedPayload = $payload;
        $changedPayload['items'][0]['quantity'] = 2;
        $changedPayload['payments'][0]['amount'] = 200;

        $this->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $changedPayload)
            ->assertStatus(409);

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(4.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
    }

    public function test_sales_audit_duplicates_detects_same_sale_fingerprint_within_window(): void
    {
        [$business, $user] = $this->tenant();
        $product = $this->product($business, stock: 5, salePrice: 100);

        $first = $this->historicalSale($business, $user, $product, 1, 98, now());
        $second = $this->historicalSale($business, $user, $product, 1, 99, now()->addSeconds(20));

        $this->artisan('sales:audit-duplicates', [
            '--business' => $business->id,
            '--window-seconds' => 60,
            '--report' => true,
        ])
            ->expectsOutputToContain('grupos_duplicados: 1')
            ->expectsOutputToContain("ventas {$first->id}, {$second->id}")
            ->assertExitCode(0);
    }

    public function test_sales_audit_duplicates_does_not_match_same_total_with_different_items(): void
    {
        [$business, $user] = $this->tenant();
        $firstProduct = $this->product($business, name: 'A', stock: 5, salePrice: 100);
        $secondProduct = $this->product($business, name: 'B', stock: 5, salePrice: 100);

        $this->historicalSale($business, $user, $firstProduct, 1, 98, now());
        $this->historicalSale($business, $user, $secondProduct, 1, 99, now()->addSeconds(20));

        $this->artisan('sales:audit-duplicates', [
            '--business' => $business->id,
            '--window-seconds' => 60,
        ])
            ->expectsOutputToContain('grupos_duplicados: 0')
            ->assertExitCode(0);
    }

    public function test_repair_dry_run_does_not_modify_duplicate_sale(): void
    {
        [$business, $user] = $this->tenant();
        $product = $this->product($business, stock: 5, salePrice: 100);
        $keep = $this->historicalSale($business, $user, $product, 1, 98, now());
        $duplicate = $this->historicalSale($business, $user, $product, 1, 99, now()->addSeconds(20));

        $this->artisan('sales:audit-duplicates', [
            '--business' => $business->id,
            '--keep-sale-id' => $keep->id,
            '--duplicate-sale-id' => $duplicate->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('modo: dry-run')
            ->assertExitCode(0);

        $this->assertSame('completed', $duplicate->refresh()->status);
    }

    public function test_repair_blocks_duplicate_with_fel_document(): void
    {
        [$business, $user] = $this->tenant();
        $product = $this->product($business, stock: 5, salePrice: 100);
        $keep = $this->historicalSale($business, $user, $product, 1, 98, now());
        $duplicate = $this->historicalSale($business, $user, $product, 1, 99, now()->addSeconds(20));
        ElectronicDocument::query()->create([
            'business_id' => $business->id,
            'sale_id' => $duplicate->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'document_type' => 'invoice',
            'status' => 'certified',
        ]);

        $this->artisan('sales:audit-duplicates', [
            '--business' => $business->id,
            '--keep-sale-id' => $keep->id,
            '--duplicate-sale-id' => $duplicate->id,
            '--confirm' => true,
        ])
            ->expectsOutputToContain('Requiere anulacion fiscal')
            ->assertExitCode(1);

        $this->assertSame('completed', $duplicate->refresh()->status);
    }

    public function test_repair_confirm_cancels_duplicate_and_reverses_stock_and_cash_when_cash_session_is_open(): void
    {
        [$business, $user] = $this->tenant();
        $product = $this->product($business, stock: 3, salePrice: 100);
        $session = $this->openCashRegister($business, $user);
        $keep = $this->historicalSale($business, $user, $product, 1, 98, now(), withEffects: true, session: $session);
        $duplicate = $this->historicalSale($business, $user, $product, 1, 99, now()->addSeconds(20), withEffects: true, session: $session);

        $this->artisan('sales:audit-duplicates', [
            '--business' => $business->id,
            '--keep-sale-id' => $keep->id,
            '--duplicate-sale-id' => $duplicate->id,
            '--confirm' => true,
        ])
            ->expectsOutputToContain('modo: confirm')
            ->assertExitCode(0);

        $this->assertSame('cancelled', $duplicate->refresh()->status);
        $this->assertStringContainsString('conserva venta '.$keep->id, (string) $duplicate->cancellation_reason);
        $this->assertSame(2.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
        $this->assertSame(1, CashMovement::query()->where('type', 'sale_cash_cancel')->count());
        $this->assertSame(100.0, (float) $session->refresh()->expected_cash);
        $this->assertSame(2, Sale::query()->count());
    }

    public function test_repair_confirm_blocks_cash_duplicate_when_cash_session_is_closed(): void
    {
        [$business, $user] = $this->tenant();
        $product = $this->product($business, stock: 3, salePrice: 100);
        $session = $this->openCashRegister($business, $user);
        $keep = $this->historicalSale($business, $user, $product, 1, 98, now(), withEffects: true, session: $session);
        $duplicate = $this->historicalSale($business, $user, $product, 1, 99, now()->addSeconds(20), withEffects: true, session: $session);
        $session->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
        ])->save();

        $this->artisan('sales:audit-duplicates', [
            '--business' => $business->id,
            '--keep-sale-id' => $keep->id,
            '--duplicate-sale-id' => $duplicate->id,
            '--confirm' => true,
        ])
            ->expectsOutputToContain('sesion cerrada')
            ->assertExitCode(1);

        $this->assertSame('completed', $duplicate->refresh()->status);
        $this->assertSame(1.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
        $this->assertSame(0, CashMovement::query()->where('type', 'sale_cash_cancel')->count());
    }

    public function test_sale_cancel_blocks_a_second_distinct_key_without_reversing_stock_or_cash_twice(): void
    {
        [$business, $user] = $this->tenant();
        Permissions::assignDirectPermissions($user, [Permissions::SALES_CANCEL]);
        $product = $this->product($business, stock: 5, salePrice: 100);
        $session = $this->openCashRegister($business, $user);
        $sale = $this->historicalSale($business, $user, $product, 1, 100, now(), withEffects: true, session: $session);

        $this->actingAs($user)->post(route('sales.cancel', $sale), [
            'idempotency_key' => 'idem-sale-cancel-1',
            'reason' => 'Error de captura',
        ])->assertRedirect(route('sales.show', $sale));

        $this->actingAs($user)->post(route('sales.cancel', $sale), [
            'idempotency_key' => 'idem-sale-cancel-2',
            'reason' => 'Error de captura',
        ])->assertSessionHasErrors('reason');

        $this->assertSame('cancelled', $sale->refresh()->status);
        $this->assertSame(5.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
        $this->assertSame(1, CashMovement::query()->where('type', 'sale_cash_cancel')->count());
        $this->assertSame(0.0, (float) $session->refresh()->expected_cash);
    }

    public function test_replayed_invoice_idempotency_without_certified_document_returns_safe_error(): void
    {
        [$business, $user] = $this->tenant();
        $this->enableFelInvoices($business);
        $product = $this->product($business, stock: 5, salePrice: 100);
        $branch = BranchInventory::activeBranch($business->id);
        $payload = $this->salePayload($product, 'idem-invoice-pending-fel', 'invoice', [
            'name' => 'Consumidor Final',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'consumidor_final' => true,
        ]);
        $sale = $this->historicalSale($business, $user, $product, 1, 100, now());
        $sale->forceFill([
            'document_type' => 'invoice',
            'status' => 'completed',
        ])->save();
        ElectronicDocument::query()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'document_type' => 'invoice',
            'status' => 'pending',
        ]);

        OperationIdempotencyKey::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'operation_type' => 'pos_sale',
            'idempotency_key' => 'idem-invoice-pending-fel',
            'request_hash' => IdempotencyService::requestHash($this->expectedSaleIdempotencyPayload($payload, $branch->id, $business->id, $user->id)),
            'status' => IdempotencyService::STATUS_COMPLETED,
            'result_type' => 'sale',
            'result_id' => $sale->id,
            'response_payload' => ['sale_id' => $sale->id, 'document_type' => 'invoice'],
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $payload)
            ->assertRedirect(route('sales.create'))
            ->assertSessionHasErrors('document_type');

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(0, ElectronicDocument::query()->where('status', 'certified')->count());
    }

    public function test_pos_reuses_one_generic_final_consumer_customer(): void
    {
        [$business, $user] = $this->tenant();
        $product = $this->product($business, stock: 5, salePrice: 100);
        $this->openCashRegister($business, $user);
        $customer = [
            'name' => 'Consumidor Final',
            'doc_type' => 'CF',
            'doc_number' => 'C/F',
            'consumidor_final' => true,
        ];

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($product, 'generic-cf-sale-1', customer: $customer))
            ->assertRedirect();
        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($product, 'generic-cf-sale-2', customer: $customer))
            ->assertRedirect();

        $customers = Customer::query()->where('business_id', $business->id)->get();
        $saleCustomerIds = Sale::query()->where('business_id', $business->id)->pluck('customer_id')->unique()->values();

        $this->assertCount(1, $customers);
        $this->assertSame('CF', $customers->first()->normalized_tax_id);
        $this->assertSame([$customers->first()->id], $saleCustomerIds->all());
    }

    public function test_pos_keeps_personalized_final_consumer_separate_from_generic_customer(): void
    {
        [$business, $user] = $this->tenant();
        $product = $this->product($business, stock: 5, salePrice: 100);
        $this->openCashRegister($business, $user);

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($product, 'generic-cf-sale-3', customer: [
                'name' => 'Consumidor Final',
                'doc_number' => 'CF',
                'consumidor_final' => true,
            ]))
            ->assertRedirect();
        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($product, 'personal-cf-sale-1', customer: [
                'name' => 'Juan Perez',
                'doc_number' => 'CF',
                'consumidor_final' => true,
            ]))
            ->assertRedirect();

        $this->assertSame(2, Customer::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, Customer::query()
            ->where('business_id', $business->id)
            ->where('name', 'Consumidor Final')
            ->count());
        $this->assertSame(1, Customer::query()
            ->where('business_id', $business->id)
            ->where('name', 'Juan Perez')
            ->count());
    }

    public function test_pos_reuses_existing_real_tax_id_after_normalization(): void
    {
        [$business, $user] = $this->tenant();
        $product = $this->product($business, stock: 5, salePrice: 100);
        $this->openCashRegister($business, $user);
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente con NIT',
            'doc_type' => 'NIT',
            'doc_number' => '5728-9085',
            'country' => 'GT',
        ]);

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($product, 'real-nit-sale-1', customer: [
                'name' => 'Cliente con NIT',
                'doc_type' => 'NIT',
                'doc_number' => '57289085',
            ]))
            ->assertRedirect();

        $this->assertSame(1, Customer::query()->where('business_id', $business->id)->count());
        $this->assertSame($customer->id, Sale::query()->where('business_id', $business->id)->sole()->customer_id);
    }

    private function tenant(): array
    {
        $business = Business::query()->create([
            'name' => 'Tenant',
            'slug' => 'tenant-'.uniqid(),
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
        ]);

        foreach (['pos', 'cash_register'] as $module) {
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
        ]);
        Permissions::assignRole($user, 'cashier');

        return [$business, $user];
    }

    private function product(Business $business, string $name = 'Producto', int $stock = 5, float $salePrice = 100): Product
    {
        $product = Product::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => 'SKU-'.uniqid(),
            'cost_price' => 50,
            'sale_price' => $salePrice,
            'stock' => $stock,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $branch = BranchInventory::defaultBranch($business->id);

        ProductBranchStock::query()->updateOrCreate(
            ['business_id' => $business->id, 'branch_id' => $branch->id, 'product_id' => $product->id],
            ['stock' => $stock],
        );

        PriceType::query()->where('business_id', $business->id)->update(['is_default' => false]);
        $priceType = PriceType::query()->updateOrCreate(
            ['business_id' => $business->id, 'name' => 'General'],
            ['is_default' => true, 'is_active' => true],
        );

        ProductPrice::query()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'price_type_id' => $priceType->id,
            'price' => $salePrice,
            'is_active' => true,
        ]);

        return $product;
    }

    private function openCashRegister(Business $business, User $user): CashRegisterSession
    {
        return CashRegisterSession::query()->create([
            'business_id' => $business->id,
            'branch_id' => BranchInventory::activeBranch($business->id)->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);
    }

    private function salePayload(
        Product $product,
        string $idempotencyKey,
        string $documentType = 'receipt',
        ?array $customer = null,
    ): array
    {
        return [
            'idempotency_key' => $idempotencyKey,
            'document_type' => $documentType,
            'customer' => $customer,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => 100,
                ],
            ],
        ];
    }

    private function expectedSaleIdempotencyPayload(array $data, int $branchId, int $businessId, int $userId): array
    {
        return [
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'operation_type' => 'pos_sale',
            'document_type' => $data['document_type'] ?? null,
            'payment_condition' => $data['payment_condition'] ?? 'paid',
            'due_date' => $data['due_date'] ?? null,
            'note' => $data['note'] ?? null,
            'customer' => $data['customer'] ?? null,
            'payments' => $data['payments'] ?? [],
            'items' => collect($data['items'] ?? [])
                ->map(fn (array $item) => [
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'price_type_id' => $item['price_type_id'] ?? null,
                    'unit_price' => isset($item['unit_price']) ? round((float) $item['unit_price'], 4) : null,
                    'price_source' => $item['price_source'] ?? null,
                    'manual_price' => (bool) ($item['manual_price'] ?? false),
                    'credit_line_id' => $item['credit_line_id'] ?? null,
                ])
                ->sortBy(fn (array $item) => implode('|', [
                    $item['product_id'],
                    $item['credit_line_id'] ?? '',
                    $item['price_type_id'] ?? '',
                    $item['unit_price'] ?? '',
                    $item['quantity'],
                ]))
                ->values()
                ->all(),
            'discount' => $data['discount'] ?? null,
        ];
    }

    private function enableFelInvoices(Business $business): void
    {
        TenantSetting::query()
            ->where('business_id', $business->id)
            ->update(['allow_invoices' => true]);

        TenantModule::query()->create([
            'business_id' => $business->id,
            'module' => 'fel_gt',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $settings = TenantFelSetting::query()->create([
            'business_id' => $business->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'enabled' => true,
            'issuer_tax_id' => '1234567',
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
    }

    private function historicalSale(
        Business $business,
        User $user,
        Product $product,
        int $quantity,
        int $number,
        mixed $createdAt,
        bool $withEffects = false,
        ?CashRegisterSession $session = null,
    ): Sale {
        $branch = BranchInventory::defaultBranch($business->id);
        $total = $quantity * (float) $product->sale_price;

        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'business_number' => $number,
            'branch_id' => $branch->id,
            'customer_id' => null,
            'customer_name' => null,
            'customer_doc_type' => null,
            'customer_doc_number' => null,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'amount_paid' => $total,
            'credit_balance' => 0,
            'is_credit_sale' => false,
            'document_type' => 'receipt',
            'status' => 'completed',
            'created_by' => $user->id,
            'subtotal_before_discount' => $total,
            'discount_amount' => 0,
            'total' => $total,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        SaleItem::query()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $quantity,
            'unit_price' => $product->sale_price,
            'unit_cost' => $product->cost_price,
            'total_cost' => $quantity * (float) $product->cost_price,
            'profit_amount' => $total - ($quantity * (float) $product->cost_price),
            'total' => $total,
        ]);

        SalePayment::query()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'method' => 'cash',
            'amount' => $total,
        ]);

        if ($withEffects) {
            BranchInventory::decrease($product, $branch->id, $quantity);
            StockMovement::query()->create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'type' => 'sale',
                'quantity' => -1 * $quantity,
                'previous_stock' => null,
                'new_stock' => null,
                'note' => 'Venta '.$number,
                'created_by' => $user->id,
            ]);

            if ($session) {
                CashMovement::query()->create([
                    'business_id' => $business->id,
                    'branch_id' => $branch->id,
                    'cash_register_session_id' => $session->id,
                    'type' => 'sale_cash',
                    'amount' => $total,
                    'reference_type' => 'sale',
                    'reference_id' => $sale->id,
                    'description' => 'Venta '.$number,
                    'created_by' => $user->id,
                ]);
                $session->forceFill(['expected_cash' => (float) $session->expected_cash + $total])->save();
            }
        }

        return $sale;
    }
}
