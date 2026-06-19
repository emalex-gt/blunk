<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\CreditCustomerTransfer;
use App\Models\CreditReceipt;
use App\Models\CreditReceiptLine;
use App\Models\Customer;
use App\Models\CustomerAccountMovement;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditPayment;
use App\Models\CustomerCreditPaymentAllocation;
use App\Models\ElectronicDocument;
use App\Models\FelReconciliationRequest;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductPrice;
use App\Models\Purchase;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Fel\FelException;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Services\Fel\Providers\Digifact\DigifactNucJsonBuilder;
use App\Support\BranchInventory;
use App\Support\FelPhraseRenderer;
use App\Support\Permissions;
use App\Support\StockAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class CriticalPosFelFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'Critical POS/FEL feature tests must run on PostgreSQL. Copy .env.testing.example to .env.testing and use composer test:pgsql.'
        );

        Permissions::syncDefaults();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_pos_sale_basic_flow_creates_lines_payment_and_deducts_integer_stock(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register']);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->openCashRegister($business, $user);

        $response = $this
            ->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $this->salePayload($product, quantity: 2, total: 200));

        $response->assertRedirect(route('sales.create'));
        $response->assertSessionHasNoErrors();

        $sale = Sale::query()->where('business_id', $business->id)->firstOrFail();
        $line = SaleItem::query()->where('sale_id', $sale->id)->firstOrFail();
        $payment = SalePayment::query()->where('sale_id', $sale->id)->firstOrFail();

        $this->assertSame(2, $line->quantity);
        $this->assertSame('200.00', (string) $sale->total);
        $this->assertSame('cash', $payment->method);
        $this->assertSame('200.00', (string) $payment->amount);
        $this->assertSame(8.0, (float) ProductBranchStock::query()
            ->where('business_id', $business->id)
            ->where('product_id', $product->id)
            ->value('stock'));
    }

    public function test_pos_sale_rejects_decimal_quantities(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register']);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->openCashRegister($business, $user);

        $payload = $this->salePayload($product, quantity: '1.5', total: 150);

        $response = $this
            ->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $payload);

        $response->assertRedirect(route('sales.create'));
        $response->assertSessionHasErrors(['items.0.quantity']);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_cf_invoice_at_or_above_2500_is_blocked_before_digifact_certification(): void
    {
        [$business, $user] = $this->tenant(country: 'GT', modules: ['pos', 'cash_register', 'fel_gt'], allowInvoices: true);
        $this->felSettings($business);
        $product = $this->product($business, stock: 10, salePrice: 2500);
        $this->openCashRegister($business, $user);

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->never();
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $response = $this
            ->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $this->salePayload(
                $product,
                quantity: 1,
                total: 2500,
                documentType: 'invoice',
                customer: [
                    'name' => 'Consumidor Final',
                    'doc_type' => 'CF',
                    'doc_number' => 'CF',
                    'country' => 'GT',
                    'consumidor_final' => true,
                ],
            ));

        $response->assertRedirect(route('sales.create'));
        $response->assertSessionHasErrors(['document_type']);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('electronic_documents', 0);
    }

    public function test_fel_invoice_is_blocked_when_tenant_switch_is_disabled_even_if_module_is_enabled(): void
    {
        [$business, $user] = $this->tenant(country: 'GT', modules: ['pos', 'cash_register', 'fel_gt'], allowInvoices: true);
        $this->felSettings($business, enabled: false);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->openCashRegister($business, $user);

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->never();
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $response = $this
            ->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $this->salePayload(
                $product,
                quantity: 1,
                total: 100,
                documentType: 'invoice',
                customer: [
                    'name' => 'Consumidor Final',
                    'doc_type' => 'CF',
                    'doc_number' => 'CF',
                    'country' => 'GT',
                    'consumidor_final' => true,
                ],
            ));

        $response->assertSessionHasErrors([
            'document_type' => 'La facturación electrónica FEL no está habilitada.',
        ]);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_fel_invoice_is_blocked_when_module_is_disabled_even_if_tenant_switch_is_enabled(): void
    {
        [$business, $user] = $this->tenant(country: 'GT', modules: ['pos', 'cash_register'], allowInvoices: true);
        $this->felSettings($business, enabled: true);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->openCashRegister($business, $user);

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->never();
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $response = $this
            ->actingAs($user)
            ->post(route('sales.store'), $this->salePayload(
                $product,
                quantity: 1,
                total: 100,
                documentType: 'invoice',
                customer: [
                    'name' => 'Consumidor Final',
                    'doc_type' => 'CF',
                    'doc_number' => 'CF',
                    'country' => 'GT',
                    'consumidor_final' => true,
                ],
            ));

        $response->assertSessionHasErrors([
            'document_type' => 'La facturación electrónica FEL no está habilitada.',
        ]);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_general_discount_is_distributed_proportionally_with_last_line_rounding(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register', 'discounts'], role: 'owner');
        $productA = $this->product($business, name: 'Line A', stock: 10, salePrice: 1000);
        $productB = $this->product($business, name: 'Line B', stock: 10, salePrice: 1500);
        $this->openCashRegister($business, $user);

        $response = $this
            ->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $this->salePayload(
                $productA,
                quantity: 2,
                total: 3000,
                items: [
                    ['product' => $productA, 'quantity' => 2],
                    ['product' => $productB, 'quantity' => 1],
                ],
                discount: [
                    'type' => 'fixed',
                    'value' => 500,
                    'reason' => 'Autorizado por administración',
                ],
            ));

        $response->assertRedirect(route('sales.create'));
        $response->assertSessionHasNoErrors();

        $sale = Sale::query()->firstOrFail();
        $lines = SaleItem::query()->where('sale_id', $sale->id)->orderBy('id')->get();

        $this->assertSame('3500.00', (string) $sale->subtotal_before_discount);
        $this->assertSame('500.00', (string) $sale->discount_amount);
        $this->assertSame('3000.00', (string) $sale->total);
        $this->assertSame('285.71', (string) $lines[0]->discount_amount);
        $this->assertSame('214.29', (string) $lines[1]->discount_amount);
        $this->assertSame('500.00', number_format($lines->sum(fn (SaleItem $line) => (float) $line->discount_amount), 2, '.', ''));
    }

    public function test_administration_cannot_disable_both_sale_document_types(): void
    {
        [$business] = $this->tenant(modules: ['pos']);
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($superAdmin)->put(route('super-admin.tenants.update', $business), [
            'name' => $business->name,
            'country' => 'GT',
            'is_active' => true,
            'use_product_images' => true,
            'max_users' => 10,
            'receipt_format' => 'ticket',
            'allow_receipts' => false,
            'allow_invoices' => false,
            'modules' => ['pos'],
        ]);

        $response->assertSessionHasErrors(['allow_receipts']);
    }

    public function test_tenant_fel_settings_page_does_not_expose_legacy_establishment_fields(): void
    {
        [$business] = $this->tenant(country: 'GT', modules: ['pos', 'fel_gt']);
        $this->felSettings($business);
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->get(route('super-admin.tenants.edit', $business))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Tenants/Form')
                ->missing('felSettings.establishment_code')
                ->missing('felSettings.establishment_name')
                ->missing('felSettings.establishment_address')
                ->missing('felSettings.establishment_postal_code')
                ->missing('felSettings.establishment_municipality')
                ->missing('felSettings.establishment_department')
                ->missing('felSettings.establishment_country')
            );
    }

    public function test_super_admin_branch_page_exposes_fel_establishment_fields(): void
    {
        [$business] = $this->tenant(country: 'GT', modules: ['pos', 'fel_gt']);
        $this->felSettings($business);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $branch->update([
            'fel_establishment_code' => '1',
            'fel_establishment_name' => 'Sucursal Principal FEL',
            'fel_address' => 'Ciudad',
            'fel_postal_code' => '01001',
            'fel_municipality' => 'Guatemala',
            'fel_department' => 'Guatemala',
            'fel_country' => 'GT',
        ]);
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->get(route('super-admin.tenants.branches', $business))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Tenants/Branches')
                ->where('branches.0.fel_establishment_code', '1')
                ->where('branches.0.fel_establishment_name', 'Sucursal Principal FEL')
                ->where('branches.0.fel_address', 'Ciudad')
                ->where('branches.0.fel_postal_code', '01001')
            );
    }

    public function test_only_receipt_enabled_uses_receipt_when_request_has_no_document_type(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register']);
        $product = $this->product($business);
        $this->openCashRegister($business, $user);
        $payload = $this->salePayload($product, quantity: 1, total: 100);
        unset($payload['document_type']);
        $payload['customer'] = [
            'name' => 'Consumidor Final',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'country' => 'GT',
            'consumidor_final' => true,
        ];

        $this->actingAs($user)
            ->post(route('sales.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame('receipt', Sale::query()->firstOrFail()->document_type);
    }

    public function test_only_invoice_enabled_uses_invoice_when_fel_is_ready_and_request_has_no_document_type(): void
    {
        [$business, $user] = $this->tenant(
            country: 'GT',
            modules: ['pos', 'cash_register', 'fel_gt'],
            allowReceipts: false,
            allowInvoices: true,
        );
        $this->felSettings($business);
        $product = $this->product($business);
        $this->openCashRegister($business, $user);
        $payload = $this->salePayload($product, quantity: 1, total: 100);
        unset($payload['document_type']);
        $payload['customer'] = [
            'name' => 'Consumidor Final',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'country' => 'GT',
            'consumidor_final' => true,
        ];

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->once()->andReturnUsing(function (Sale $sale) {
            $document = $sale->electronicDocument;
            $document->update(['status' => 'certified', 'uuid' => 'UUID-TEST']);

            return $document->refresh();
        });
        $digifact->shouldReceive('recordSaleRequestTiming')->once();
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $this->actingAs($user)
            ->post(route('sales.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame('invoice', Sale::query()->firstOrFail()->document_type);
    }

    public function test_receipt_disabled_is_not_exposed_as_available_pos_document_type(): void
    {
        [$business, $user] = $this->tenant(
            country: 'GT',
            modules: ['pos', 'fel_gt'],
            allowReceipts: false,
            allowInvoices: true,
        );
        $this->felSettings($business);

        $this->actingAs($user)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/POS')
                ->where('available_document_types', ['invoice'])
                ->where('credit_available', false)
            );
    }

    public function test_pos_exposes_only_invoice_and_credit_when_receipt_is_disabled(): void
    {
        [$business, $user] = $this->tenant(
            country: 'GT',
            modules: ['pos', 'fel_gt', 'credits'],
            allowReceipts: false,
            allowInvoices: true,
            enableCredits: true,
        );
        $this->felSettings($business);

        $this->actingAs($user)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/POS')
                ->where('available_document_types', ['invoice'])
                ->where('credit_available', true)
            );
    }

    public function test_only_invoice_enabled_exposes_one_pos_document_type(): void
    {
        [$business, $user] = $this->tenant(
            country: 'GT',
            modules: ['pos', 'fel_gt'],
            allowReceipts: false,
            allowInvoices: true,
        );
        $this->felSettings($business);

        $this->actingAs($user)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/POS')
                ->where('available_document_types', ['invoice'])
                ->where('credit_available', false)
            );
    }

    public function test_only_credit_enabled_exposes_credit_without_sale_document_types(): void
    {
        [, $user] = $this->tenant(
            modules: ['pos', 'credits'],
            allowReceipts: false,
            allowInvoices: false,
            enableCredits: true,
        );

        $this->actingAs($user)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/POS')
                ->where('available_document_types', [])
                ->where('credit_available', true)
            );
    }

    public function test_invoice_disabled_rejects_invoice_request(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register']);
        $product = $this->product($business);
        $this->openCashRegister($business, $user);

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($product, quantity: 1, total: 100, documentType: 'invoice'))
            ->assertSessionHasErrors([
                'document_type' => 'El tipo de documento seleccionado no está habilitado.',
            ]);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_receipt_disabled_rejects_receipt_request(): void
    {
        [$business, $user] = $this->tenant(
            country: 'GT',
            modules: ['pos', 'cash_register', 'fel_gt'],
            allowReceipts: false,
            allowInvoices: true,
        );
        $this->felSettings($business);
        $product = $this->product($business);
        $this->openCashRegister($business, $user);

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($product, quantity: 1, total: 100))
            ->assertSessionHasErrors([
                'document_type' => 'El tipo de documento seleccionado no está habilitado.',
            ]);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_credit_receipt_creation_is_rejected_without_credit_create_permission(): void
    {
        [$business, $user] = $this->tenant(
            modules: ['pos', 'credits'],
            role: 'reports',
            enableCredits: true,
        );
        $product = $this->product($business);

        $this->actingAs($user)
            ->post(route('credits.receipts.store'), $this->creditPayload($product, 1))
            ->assertForbidden();
    }

    public function test_invoice_request_is_rejected_when_fel_is_not_configured(): void
    {
        [$business, $user] = $this->tenant(
            country: 'GT',
            modules: ['pos', 'cash_register', 'fel_gt'],
            allowInvoices: true,
        );
        $product = $this->product($business);
        $this->openCashRegister($business, $user);

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($product, quantity: 1, total: 100, documentType: 'invoice'))
            ->assertSessionHasErrors(['document_type']);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_both_document_types_enabled_respects_requested_receipt(): void
    {
        [$business, $user] = $this->tenant(
            country: 'GT',
            modules: ['pos', 'cash_register', 'fel_gt'],
            allowInvoices: true,
        );
        $this->felSettings($business);
        $product = $this->product($business);
        $this->openCashRegister($business, $user);

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($product, quantity: 1, total: 100, documentType: 'receipt'))
            ->assertSessionHasNoErrors();

        $this->assertSame('receipt', Sale::query()->firstOrFail()->document_type);
    }

    public function test_manual_price_is_rejected_when_tenant_setting_is_disabled(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register'], role: 'owner', allowManualPrice: false);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->openCashRegister($business, $user);

        $response = $this
            ->actingAs($user)
            ->post(route('sales.store'), $this->salePayload(
                $product,
                quantity: 1,
                total: 50,
                itemOverrides: [
                    'manual_price' => true,
                    'price_source' => 'manual',
                    'unit_price' => 50,
                ],
            ));

        $response->assertForbidden();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_manual_price_requires_permission_even_when_tenant_setting_is_enabled(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register'], role: 'cashier', allowManualPrice: true);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->openCashRegister($business, $user);

        $response = $this
            ->actingAs($user)
            ->post(route('sales.store'), $this->salePayload(
                $product,
                quantity: 1,
                total: 100,
                itemOverrides: [
                    'manual_price' => true,
                    'price_source' => 'manual',
                    'unit_price' => 100,
                ],
            ));

        $response->assertForbidden();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_direct_permission_allows_manual_price_with_required_margin(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register'], role: 'cashier', allowManualPrice: true);
        TenantSetting::query()->where('business_id', $business->id)->update([
            'manual_price_min_margin_percent' => 20,
        ]);
        Permissions::assignDirectPermissions($user, [Permissions::POS_MANUAL_PRICE]);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->openCashRegister($business, $user);

        $this
            ->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $this->salePayload(
                $product,
                quantity: 1,
                total: 55,
                itemOverrides: [
                    'manual_price' => true,
                    'price_source' => 'manual',
                    'unit_price' => 55,
                ],
            ))
            ->assertSessionHasErrors(['items']);

        $this->assertDatabaseCount('sales', 0);

        $this
            ->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $this->salePayload(
                $product,
                quantity: 1,
                total: 60,
                itemOverrides: [
                    'manual_price' => true,
                    'price_source' => 'manual',
                    'unit_price' => 60,
                ],
            ))
            ->assertSessionHasNoErrors();

        $this->assertSame('60.00', (string) SaleItem::query()->firstOrFail()->unit_price);
    }

    public function test_manual_price_error_does_not_expose_cost_or_margin(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register'], role: 'cashier', allowManualPrice: true);
        TenantSetting::query()->where('business_id', $business->id)->update([
            'manual_price_min_margin_percent' => 20,
        ]);
        Permissions::assignDirectPermissions($user, [Permissions::POS_MANUAL_PRICE]);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->openCashRegister($business, $user);

        $response = $this
            ->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $this->salePayload(
                $product,
                quantity: 1,
                total: 55,
                itemOverrides: [
                    'manual_price' => true,
                    'price_source' => 'manual',
                    'unit_price' => 55,
                ],
            ));

        $response->assertSessionHasErrors(['items' => 'Este precio no está permitido.']);
        $this->assertStringNotContainsString('costo', mb_strtolower(session('errors')->first('items')));
        $this->assertStringNotContainsString('margen', mb_strtolower(session('errors')->first('items')));
    }

    public function test_role_permission_and_super_admin_bypass_permission_checks(): void
    {
        [$business, $user] = $this->tenant(role: 'cashier');
        $role = Role::query()->where('key', 'cashier')->whereNull('business_id')->firstOrFail();
        $permission = Permission::query()->where('key', Permissions::SALES_DISCOUNT_APPLY)->firstOrFail();

        $this->assertFalse(Permissions::userHas($user, Permissions::SALES_DISCOUNT_APPLY));

        $role->permissions()->attach($permission->id);
        $user->refresh()->load('roles.permissions');

        $this->assertTrue(Permissions::userHas($user, Permissions::SALES_DISCOUNT_APPLY));

        $superAdmin = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'super_admin',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $this->assertTrue(Permissions::userHas($superAdmin, Permissions::SUPER_ADMIN_ROLES_MANAGE));
    }

    public function test_tenant_user_cannot_create_roles_but_can_assign_existing_roles_to_users(): void
    {
        [$business, $owner] = $this->tenant(role: 'owner');

        $this->actingAs($owner)
            ->post(route('super-admin.security.roles.store'), [
                'key' => 'tenant_created',
                'name' => 'Tenant Created',
                'permissions' => [],
            ])
            ->assertForbidden();

        $response = $this->actingAs($owner)
            ->post(route('users.store'), [
                'name' => 'Nuevo Cajero',
                'email' => 'cashier-'.uniqid().'@test.test',
                'role' => 'cashier',
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

        $response->assertRedirect();
        $user = User::query()->where('business_id', $business->id)->where('email', 'like', 'cashier-%')->firstOrFail();

        $this->assertTrue($user->roles()->where('key', 'cashier')->exists());
    }

    public function test_super_admin_can_manage_security_roles_permissions_and_assignments(): void
    {
        [$business] = $this->tenant(role: 'cashier');
        $superAdmin = User::factory()->create([
            'business_id' => null,
            'role' => 'super_admin',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $permissionKey = 'custom.audit.'.uniqid();
        $roleKey = 'custom_role_'.uniqid();

        $this->actingAs($superAdmin)
            ->get(route('super-admin.security.roles'))
            ->assertOk();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.security.permissions.store'), [
                'key' => $permissionKey,
                'name' => 'Permiso auditoria',
                'group' => 'Auditoria',
                'description' => 'Permiso temporal de prueba.',
            ])
            ->assertRedirect();

        $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.security.roles.store'), [
                'scope' => 'tenant',
                'business_id' => $business->id,
                'key' => $roleKey,
                'name' => 'Rol Auditoria',
                'is_active' => true,
                'permissions' => [$permissionKey],
            ])
            ->assertRedirect();

        $role = Role::query()->where('key', $roleKey)->where('business_id', $business->id)->firstOrFail();
        $this->assertTrue($role->permissions()->where('key', $permissionKey)->exists());

        $tenantUser = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'cashier',
            'is_active' => true,
            'is_super_admin' => false,
        ]);

        $this->actingAs($superAdmin)
            ->put(route('super-admin.security.assignments.update', $tenantUser), [
                'role_ids' => [$role->id],
                'permission_ids' => [],
            ])
            ->assertRedirect();

        $this->assertTrue($tenantUser->fresh()->roles()->whereKey($role->id)->exists());
        $this->assertTrue(Permissions::userHas($tenantUser->fresh(), $permissionKey));

        $this->actingAs($superAdmin)
            ->delete(route('super-admin.security.roles.destroy', $role))
            ->assertStatus(422);
    }

    public function test_tenant_user_permissions_are_action_specific(): void
    {
        [$business, $user] = $this->tenant(role: 'cashier');
        $user->roles()->detach();
        Permissions::assignDirectPermissions($user, [Permissions::USERS_VIEW]);

        $this->actingAs($user)
            ->post(route('users.store'), [
                'name' => 'Sin permiso',
                'email' => 'blocked-'.uniqid().'@test.test',
                'role' => 'cashier',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertForbidden();

        Permissions::assignDirectPermissions($user, [
            Permissions::USERS_VIEW,
            Permissions::USERS_CREATE,
            Permissions::USERS_ASSIGN_ROLES,
        ]);

        $this->actingAs($user)
            ->post(route('users.store'), [
                'name' => 'Permitido',
                'email' => 'allowed-'.uniqid().'@test.test',
                'role' => 'cashier',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'business_id' => $business->id,
            'name' => 'Permitido',
            'role' => 'cashier',
        ]);
    }

    public function test_user_without_rbac_assignment_does_not_receive_legacy_role_permissions(): void
    {
        [, $user] = $this->tenant(role: 'owner');

        $this->assertTrue(Permissions::userHas($user, Permissions::POS_SELL));

        $user->roles()->detach();
        $user->directPermissions()->detach();

        $this->assertFalse(Permissions::userHas($user->fresh(), Permissions::POS_SELL));
    }

    public function test_default_price_list_is_used_and_stored_when_multiple_price_lists_exist(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register']);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $default = PriceType::query()->where('business_id', $business->id)->where('is_default', true)->firstOrFail();
        $other = PriceType::create([
            'business_id' => $business->id,
            'name' => 'Mayorista',
            'is_default' => false,
            'is_active' => true,
        ]);

        ProductPrice::query()->updateOrCreate(
            ['business_id' => $business->id, 'product_id' => $product->id, 'price_type_id' => $default->id],
            ['price' => 123, 'is_active' => true],
        );
        ProductPrice::query()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'price_type_id' => $other->id,
            'price' => 999,
            'is_active' => true,
        ]);

        $this->openCashRegister($business, $user);

        $response = $this
            ->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $this->salePayload($product, quantity: 1, total: 123));

        $response->assertRedirect(route('sales.create'));
        $response->assertSessionHasNoErrors();

        $line = SaleItem::query()->firstOrFail();

        $this->assertSame($default->id, $line->price_type_id);
        $this->assertSame('123.00', (string) $line->unit_price);
        $this->assertSame('price_list', $line->price_source);
    }

    public function test_branch_pricing_uses_active_branch_price_in_pos(): void
    {
        [$business, $user] = $this->tenant(
            modules: ['pos', 'cash_register', 'branches'],
            role: 'owner',
            pricingScope: 'branch',
        );
        $product = $this->product($business, stock: 10, salePrice: 80);
        $default = PriceType::query()->where('business_id', $business->id)->where('is_default', true)->firstOrFail();
        $branchA = BranchInventory::defaultBranch($business->id);
        $branchB = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
        ]);

        BranchProductPrice::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branchA->id,
            'product_id' => $product->id,
            'price_type_id' => $default->id,
            'price' => 100,
            'is_active' => true,
        ]);
        BranchProductPrice::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branchB->id,
            'product_id' => $product->id,
            'price_type_id' => $default->id,
            'price' => 150,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['active_branch_id' => $branchA->id])
            ->get(route('sales.create'))
            ->assertInertia(fn (Assert $page) => $page->where('products.0.sale_price', '100.00'));

        $this->actingAs($user)
            ->withSession(['active_branch_id' => $branchB->id])
            ->get(route('sales.create'))
            ->assertInertia(fn (Assert $page) => $page->where('products.0.sale_price', '150.00'));
    }

    public function test_backend_rejects_sale_when_submitted_branch_differs_from_active_branch(): void
    {
        [$business, $user] = $this->tenant(
            modules: ['pos', 'cash_register', 'branches'],
            role: 'owner',
        );
        $product = $this->product($business, stock: 10, salePrice: 100);
        $branchA = BranchInventory::defaultBranch($business->id);
        $branchB = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
        ]);
        $this->openCashRegister($business, $user);
        $payload = $this->salePayload($product, quantity: 1, total: 100);
        $payload['branch_id'] = $branchB->id;

        $this->actingAs($user)
            ->withSession(['active_branch_id' => $branchA->id])
            ->post(route('sales.store'), $payload)
            ->assertSessionHasErrors([
                'branch_id' => 'La sucursal de la venta no coincide con la sucursal activa.',
            ]);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_backend_rejects_sale_when_submitted_branch_belongs_to_another_business(): void
    {
        [$business, $user] = $this->tenant(
            modules: ['pos', 'cash_register', 'branches'],
            role: 'owner',
        );
        [, $otherUser] = $this->tenant(
            modules: ['pos', 'branches'],
            role: 'owner',
        );
        $otherBranch = BranchInventory::defaultBranch((int) $otherUser->business_id);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->openCashRegister($business, $user);
        $payload = $this->salePayload($product, quantity: 1, total: 100);
        $payload['branch_id'] = $otherBranch->id;

        $this->actingAs($user)
            ->post(route('sales.store'), $payload)
            ->assertSessionHasErrors([
                'branch_id' => 'La sucursal de la venta no coincide con la sucursal activa.',
            ]);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_backend_rejects_credit_receipt_when_submitted_branch_differs_from_active_branch(): void
    {
        [$business, $user] = $this->tenant(
            modules: ['pos', 'credits', 'branches'],
            role: 'owner',
            enableCredits: true,
        );
        $product = $this->product($business, stock: 10, salePrice: 100);
        $branchA = BranchInventory::defaultBranch($business->id);
        $branchB = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
        ]);
        $payload = $this->creditPayload($product, 1);
        $payload['branch_id'] = $branchB->id;

        $this->actingAs($user)
            ->withSession(['active_branch_id' => $branchA->id])
            ->post(route('credits.receipts.store'), $payload)
            ->assertSessionHasErrors([
                'branch_id' => 'La sucursal de la venta no coincide con la sucursal activa.',
            ]);

        $this->assertDatabaseCount('credit_receipts', 0);
    }

    public function test_backend_rejects_credit_receipt_when_submitted_branch_belongs_to_another_business(): void
    {
        [$business, $user] = $this->tenant(
            modules: ['pos', 'credits', 'branches'],
            role: 'owner',
            enableCredits: true,
        );
        [, $otherUser] = $this->tenant(
            modules: ['pos', 'branches'],
            role: 'owner',
        );
        $otherBranch = BranchInventory::defaultBranch((int) $otherUser->business_id);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $payload = $this->creditPayload($product, 1);
        $payload['branch_id'] = $otherBranch->id;

        $this->actingAs($user)
            ->post(route('credits.receipts.store'), $payload)
            ->assertSessionHasErrors([
                'branch_id' => 'La sucursal de la venta no coincide con la sucursal activa.',
            ]);

        $this->assertDatabaseCount('credit_receipts', 0);
    }

    public function test_product_edit_in_branch_pricing_does_not_change_other_branch_or_global_price(): void
    {
        [$business, $user] = $this->tenant(
            modules: ['inventory', 'branches'],
            role: 'owner',
            pricingScope: 'branch',
        );
        $product = $this->product($business, stock: 10, salePrice: 80);
        $default = PriceType::query()->where('business_id', $business->id)->where('is_default', true)->firstOrFail();
        $branchA = BranchInventory::defaultBranch($business->id);
        $branchB = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
        ]);

        ProductPrice::query()->where('business_id', $business->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->update(['price' => 80]);
        BranchProductPrice::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branchB->id,
            'product_id' => $product->id,
            'price_type_id' => $default->id,
            'price' => 150,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['active_branch_id' => $branchA->id])
            ->put(route('products.update', $product), [
                'name' => $product->name,
                'code' => $product->code,
                'barcode' => $product->barcode,
                'cost_price' => (string) $product->cost_price,
                'sale_price' => 120,
                'stock' => 10,
                'min_stock' => 0,
                'location' => $product->location,
                'is_active' => true,
                'category_name' => null,
                'prices' => [],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('80.00', (string) ProductPrice::query()->where('business_id', $business->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
        $this->assertSame('120.00', (string) BranchProductPrice::query()->where('business_id', $business->id)->where('branch_id', $branchA->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
        $this->assertSame('150.00', (string) BranchProductPrice::query()->where('business_id', $business->id)->where('branch_id', $branchB->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
    }

    public function test_cannot_create_product_with_duplicate_code_in_same_business(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        Product::create($this->productRecord($business, ['name' => 'Bomba A', 'code' => '33100 4X700']));

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'Bomba duplicada',
                'code' => '33100 4X700',
            ]))
            ->assertSessionHasErrors(['code' => 'Ya existe un producto con este código.']);
    }

    public function test_can_create_duplicate_product_code_when_tenant_allows_it(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        TenantSetting::query()->where('business_id', $business->id)->update([
            'allow_duplicate_product_codes' => true,
        ]);
        Product::create($this->productRecord($business, ['name' => 'Bomba A', 'code' => 'DUP-CODE-001', 'barcode' => 'BAR-A']));

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'Bomba B',
                'code' => 'DUP-CODE-001',
                'barcode' => 'BAR-B',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Product::query()
            ->where('business_id', $business->id)
            ->where('code', 'DUP-CODE-001')
            ->count());
    }

    public function test_product_index_payload_includes_barcode(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        Product::create($this->productRecord($business, [
            'name' => 'Producto con barras',
            'code' => 'CODE-BAR-001',
            'barcode' => 'BAR-PAYLOAD-001',
        ]));

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Index')
                ->where('products.0.barcode', 'BAR-PAYLOAD-001')
            );
    }

    public function test_product_search_finds_by_barcode(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        Product::create($this->productRecord($business, [
            'name' => 'Producto por barras',
            'code' => 'SEARCH-CODE-001',
            'barcode' => 'SEARCH-BAR-001',
        ]));
        Product::create($this->productRecord($business, [
            'name' => 'Producto oculto',
            'code' => 'OTHER-CODE-001',
            'barcode' => 'OTHER-BAR-001',
        ]));

        $this->actingAs($user)
            ->get(route('products.index', ['search' => 'SEARCH-BAR-001']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Index')
                ->has('products', 1)
                ->where('products.0.barcode', 'SEARCH-BAR-001')
            );
    }

    public function test_missing_product_name_returns_readable_spanish_message(): void
    {
        [, $user] = $this->tenant(modules: ['inventory'], role: 'owner');

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => '',
                'code' => 'NO-NAME-001',
            ]))
            ->assertSessionHasErrors(['name' => 'El nombre es obligatorio.']);
    }

    public function test_cannot_create_product_without_code_and_barcode(): void
    {
        [, $user] = $this->tenant(modules: ['inventory'], role: 'owner');

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'Producto sin identificador',
                'code' => '',
                'barcode' => '',
            ]))
            ->assertSessionHasErrors(['code' => 'Debes ingresar código o código de barras.']);
    }

    public function test_can_create_product_with_only_code(): void
    {
        [, $user] = $this->tenant(modules: ['inventory'], role: 'owner');

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'Producto solo codigo',
                'code' => 'ONLY-CODE-001',
                'barcode' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['name' => 'Producto solo codigo', 'code' => 'ONLY-CODE-001', 'barcode' => null]);
    }

    public function test_can_create_product_with_only_barcode(): void
    {
        [, $user] = $this->tenant(modules: ['inventory'], role: 'owner');

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'Producto solo barras',
                'code' => '',
                'barcode' => 'ONLY-BAR-001',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['name' => 'Producto solo barras', 'code' => null, 'barcode' => 'ONLY-BAR-001']);
    }

    public function test_cannot_create_product_with_duplicate_barcode_in_same_business(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        Product::create($this->productRecord($business, ['name' => 'Producto A', 'barcode' => 'BRC-001']));

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'Producto B',
                'barcode' => 'BRC-001',
            ]))
            ->assertSessionHasErrors(['barcode' => 'Ya existe un producto con este código de barras.']);
    }

    public function test_can_create_duplicate_product_barcode_when_tenant_allows_it(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        TenantSetting::query()->where('business_id', $business->id)->update([
            'allow_duplicate_product_barcodes' => true,
        ]);
        Product::create($this->productRecord($business, ['name' => 'Producto A', 'code' => 'CODE-A', 'barcode' => 'DUP-BAR-001']));

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'Producto B',
                'code' => 'CODE-B',
                'barcode' => 'DUP-BAR-001',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Product::query()
            ->where('business_id', $business->id)
            ->where('barcode', 'DUP-BAR-001')
            ->count());
    }

    public function test_product_identity_check_returns_hard_error_for_duplicate_code(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        $product = Product::create($this->productRecord($business, ['name' => 'Producto A', 'code' => 'ABC 123', 'barcode' => 'BAR-ABC']));

        $response = $this->actingAs($user)
            ->getJson(route('products.check-identity', ['code' => '  abc   123  ']))
            ->assertOk()
            ->assertJsonPath('errors.code', 'Ya existe un producto con este código.')
            ->assertJsonPath('matches.0.id', $product->id)
            ->assertJsonPath('matches.0.name', 'Producto A')
            ->assertJsonPath('matches.0.code', 'ABC 123')
            ->assertJsonPath('matches.0.barcode', 'BAR-ABC');

        $this->assertArrayNotHasKey('barcode', $response->json('errors'));
    }

    public function test_product_identity_check_returns_hard_error_for_duplicate_barcode(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        $product = Product::create($this->productRecord($business, ['name' => 'Producto A', 'code' => 'CODE-A', 'barcode' => 'BAR 001']));

        $response = $this->actingAs($user)
            ->getJson(route('products.check-identity', ['barcode' => '  bar   001  ']))
            ->assertOk()
            ->assertJsonPath('errors.barcode', 'Ya existe un producto con este código de barras.')
            ->assertJsonPath('matches.0.id', $product->id);

        $this->assertArrayNotHasKey('code', $response->json('errors'));
    }

    public function test_product_identity_check_warns_for_duplicate_code_when_tenant_allows_it(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        TenantSetting::query()->where('business_id', $business->id)->update([
            'allow_duplicate_product_codes' => true,
        ]);
        $product = Product::create($this->productRecord($business, ['name' => 'Producto A', 'code' => 'DUP-CODE-WARN', 'barcode' => 'BAR-WARN-A']));

        $response = $this->actingAs($user)
            ->getJson(route('products.check-identity', ['code' => 'DUP-CODE-WARN']))
            ->assertOk()
            ->assertJsonPath('warnings.code', 'Ya existe otro producto con este código. Revisa las coincidencias antes de guardar.')
            ->assertJsonPath('matches.0.id', $product->id);

        $this->assertSame([], $response->json('errors'));
    }

    public function test_product_identity_check_warns_for_duplicate_barcode_when_tenant_allows_it(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        TenantSetting::query()->where('business_id', $business->id)->update([
            'allow_duplicate_product_barcodes' => true,
        ]);
        $product = Product::create($this->productRecord($business, ['name' => 'Producto A', 'code' => 'CODE-WARN-A', 'barcode' => 'DUP-BAR-WARN']));

        $response = $this->actingAs($user)
            ->getJson(route('products.check-identity', ['barcode' => 'DUP-BAR-WARN']))
            ->assertOk()
            ->assertJsonPath('warnings.barcode', 'Ya existe otro producto con este código de barras. Revisa las coincidencias antes de guardar.')
            ->assertJsonPath('matches.0.id', $product->id);

        $this->assertSame([], $response->json('errors'));
    }

    public function test_code_matching_existing_barcode_warns_but_does_not_block_save(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        $product = Product::create($this->productRecord($business, ['name' => 'Producto A', 'code' => 'CODE-A', 'barcode' => '789']));

        $this->actingAs($user)
            ->getJson(route('products.check-identity', ['code' => '789']))
            ->assertOk()
            ->assertJsonPath('warnings.code', 'El código ingresado coincide con el código de barras de otro producto. Revisa si ya existe.')
            ->assertJsonPath('matches.0.id', $product->id);

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'Producto posible coincidencia',
                'code' => '789',
                'barcode' => 'NEW-BAR-789',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'name' => 'Producto posible coincidencia',
            'code' => '789',
            'barcode' => 'NEW-BAR-789',
        ]);
    }

    public function test_barcode_matching_existing_code_warns_but_does_not_block_save(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        $product = Product::create($this->productRecord($business, ['name' => 'Producto A', 'code' => 'ABC123', 'barcode' => 'BAR-A']));

        $this->actingAs($user)
            ->getJson(route('products.check-identity', ['barcode' => 'ABC123']))
            ->assertOk()
            ->assertJsonPath('warnings.barcode', 'El código de barras ingresado coincide con el código de otro producto. Revisa si ya existe.')
            ->assertJsonPath('matches.0.id', $product->id);

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'Producto posible barras',
                'code' => 'NEW-CODE-ABC',
                'barcode' => 'ABC123',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'name' => 'Producto posible barras',
            'code' => 'NEW-CODE-ABC',
            'barcode' => 'ABC123',
        ]);
    }

    public function test_product_identity_check_ignores_current_product_on_update(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        $product = Product::create($this->productRecord($business, ['name' => 'Producto editable', 'code' => 'SELF-001', 'barcode' => 'SELF-BAR']));

        $response = $this->actingAs($user)
            ->getJson(route('products.check-identity', [
                'code' => 'SELF-001',
                'barcode' => 'SELF-BAR',
                'ignore_product_id' => $product->id,
            ]))
            ->assertOk();

        $this->assertSame([], $response->json('errors'));
        $this->assertSame([], $response->json('warnings'));
        $this->assertSame([], $response->json('matches'));
    }

    public function test_product_identity_check_does_not_return_matches_from_another_business(): void
    {
        [$businessA] = $this->tenant(modules: ['inventory'], role: 'owner');
        [, $userB] = $this->tenant(modules: ['inventory'], role: 'owner');
        Product::create($this->productRecord($businessA, [
            'name' => 'Producto negocio A',
            'code' => 'TENANT-A-CODE',
            'barcode' => 'TENANT-A-BAR',
        ]));

        $response = $this->actingAs($userB)
            ->getJson(route('products.check-identity', [
                'code' => 'TENANT-A-CODE',
                'barcode' => 'TENANT-A-BAR',
            ]))
            ->assertOk();

        $this->assertSame([], $response->json('errors'));
        $this->assertSame([], $response->json('warnings'));
        $this->assertSame([], $response->json('matches'));
    }

    public function test_product_identity_check_other_business_cross_field_matches_do_not_warn(): void
    {
        [$businessA] = $this->tenant(modules: ['inventory'], role: 'owner');
        [, $userB] = $this->tenant(modules: ['inventory'], role: 'owner');
        Product::create($this->productRecord($businessA, [
            'name' => 'Producto negocio A',
            'code' => 'OTHER-CODE',
            'barcode' => 'OTHER-BAR',
        ]));

        $response = $this->actingAs($userB)
            ->getJson(route('products.check-identity', [
                'code' => 'OTHER-BAR',
                'barcode' => 'OTHER-CODE',
            ]))
            ->assertOk();

        $this->assertSame([], $response->json('errors'));
        $this->assertSame([], $response->json('warnings'));
        $this->assertSame([], $response->json('matches'));
    }

    public function test_product_identity_check_ignore_product_id_from_another_business_does_not_hide_current_duplicate(): void
    {
        [$businessA] = $this->tenant(modules: ['inventory'], role: 'owner');
        [$businessB, $userB] = $this->tenant(modules: ['inventory'], role: 'owner');
        $otherBusinessProduct = Product::create($this->productRecord($businessA, [
            'name' => 'Producto negocio A',
            'code' => 'SHARED-CODE',
        ]));
        $currentBusinessProduct = Product::create($this->productRecord($businessB, [
            'name' => 'Producto negocio B',
            'code' => 'SHARED-CODE',
        ]));

        $this->actingAs($userB)
            ->getJson(route('products.check-identity', [
                'code' => 'SHARED-CODE',
                'ignore_product_id' => $otherBusinessProduct->id,
            ]))
            ->assertOk()
            ->assertJsonPath('errors.code', 'Ya existe un producto con este código.')
            ->assertJsonPath('matches.0.id', $currentBusinessProduct->id);
    }

    public function test_can_create_same_product_code_in_different_business(): void
    {
        [$businessA] = $this->tenant(modules: ['inventory'], role: 'owner');
        [, $userB] = $this->tenant(modules: ['inventory'], role: 'owner');
        Product::create($this->productRecord($businessA, ['name' => 'Producto A', 'code' => 'DUP-001', 'barcode' => 'BAR-001']));

        $this->actingAs($userB)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'Producto B',
                'code' => 'DUP-001',
                'barcode' => 'BAR-001',
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_can_update_product_without_failing_against_itself(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        $product = Product::create($this->productRecord($business, ['name' => 'Producto editable', 'code' => 'SELF-001', 'barcode' => 'SELF-BAR']));

        $this->actingAs($user)
            ->put(route('products.update', $product), $this->productFormPayload([
                'name' => 'Producto editable actualizado',
                'code' => 'SELF-001',
                'barcode' => 'SELF-BAR',
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_can_create_same_product_name_with_different_code(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        Product::create($this->productRecord($business, ['name' => 'BOMBA DE INYECCION KIA BONGO', 'code' => '33100 4X700']));

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'BOMBA DE INYECCION KIA BONGO',
                'code' => '33100 4X701',
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_duplicate_product_code_with_extra_spaces_is_blocked(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        Product::create($this->productRecord($business, ['name' => 'Bomba A', 'code' => '33100 4X700']));

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'Bomba duplicada espacios',
                'code' => '  33100   4X700  ',
            ]))
            ->assertSessionHasErrors(['code' => 'Ya existe un producto con este código.']);
    }

    public function test_duplicate_product_barcode_with_extra_spaces_is_blocked(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'owner');
        Product::create($this->productRecord($business, ['name' => 'Producto A', 'barcode' => 'BAR 001']));

        $this->actingAs($user)
            ->post(route('products.store'), $this->productFormPayload([
                'name' => 'Producto barras duplicado',
                'code' => 'DIFFERENT-CODE',
                'barcode' => '  BAR   001  ',
            ]))
            ->assertSessionHasErrors(['barcode' => 'Ya existe un producto con este código de barras.']);
    }

    public function test_price_list_mass_update_in_branch_pricing_saves_branch_price_only(): void
    {
        [$business, $user] = $this->tenant(
            modules: ['inventory', 'branches'],
            role: 'owner',
            pricingScope: 'branch',
        );
        $product = $this->product($business, stock: 10, salePrice: 80);
        $default = PriceType::query()->where('business_id', $business->id)->where('is_default', true)->firstOrFail();
        $branch = BranchInventory::defaultBranch($business->id);

        $this->actingAs($user)
            ->withSession(['active_branch_id' => $branch->id])
            ->patch(route('price-lists.prices.update', $default), [
                'prices' => [
                    ['product_id' => $product->id, 'price' => 175],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('80.00', (string) ProductPrice::query()->where('business_id', $business->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
        $this->assertSame('175.00', (string) BranchProductPrice::query()->where('business_id', $business->id)->where('branch_id', $branch->id)->where('product_id', $product->id)->where('price_type_id', $default->id)->firstOrFail()->price);
    }

    public function test_sales_receive_business_correlatives_per_business(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register']);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->openCashRegister($business, $user);

        $this->actingAs($user)->post(route('sales.store'), $this->salePayload($product, quantity: 1, total: 100))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('sales.store'), $this->salePayload($product, quantity: 1, total: 100))->assertSessionHasNoErrors();

        [$otherBusiness, $otherUser] = $this->tenant(modules: ['pos', 'cash_register']);
        $otherProduct = $this->product($otherBusiness, stock: 10, salePrice: 100);
        $this->openCashRegister($otherBusiness, $otherUser);

        $this->actingAs($otherUser)->post(route('sales.store'), $this->salePayload($otherProduct, quantity: 1, total: 100))->assertSessionHasNoErrors();

        $this->assertSame([1, 2], Sale::query()->where('business_id', $business->id)->orderBy('id')->pluck('business_number')->all());
        $this->assertSame(1, Sale::query()->where('business_id', $otherBusiness->id)->value('business_number'));
        $this->assertSame('V-1', format_sale_number(Sale::query()->where('business_id', $business->id)->orderBy('id')->first()));
    }

    public function test_purchase_counter_is_separate_from_sale_counter(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register', 'purchases'], role: 'owner');
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->openCashRegister($business, $user);

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($product, quantity: 1, total: 100))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('purchases.store'), [
                'supplier' => ['name' => 'Proveedor test'],
                'payment_method' => 'cash',
                'paid_from_cash' => false,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_cost' => 50,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $sale = Sale::query()->where('business_id', $business->id)->firstOrFail();
        $purchase = Purchase::query()->where('business_id', $business->id)->firstOrFail();

        $this->assertSame(1, $sale->business_number);
        $this->assertSame(1, $purchase->business_number);
        $this->assertSame('C-1', format_purchase_number($purchase));
    }

    public function test_branch_switch_permission_controls_active_branch_switching(): void
    {
        [$business, $user] = $this->tenant(modules: ['branches'], role: 'cashier');
        TenantSetting::query()->where('business_id', $business->id)->update(['use_branches' => true]);
        $main = BranchInventory::defaultBranch($business->id);
        $other = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
        ]);
        $user->forceFill(['current_branch_id' => $main->id])->save();

        $this->actingAs($user)
            ->post(route('branches.active'), ['branch_id' => $other->id])
            ->assertForbidden();

        Permissions::assignDirectPermissions($user->refresh(), [Permissions::BRANCHES_SWITCH]);

        $this->actingAs($user)
            ->post(route('branches.active'), ['branch_id' => $other->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($other->id, session('active_branch_id'));
    }

    public function test_product_cardex_only_shows_active_branch_movements(): void
    {
        [$business, $user] = $this->tenant(modules: ['branches', 'inventory'], role: 'owner');
        TenantSetting::query()->where('business_id', $business->id)->update(['use_branches' => true]);
        $main = BranchInventory::defaultBranch($business->id);
        $other = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
        ]);
        $user->forceFill(['current_branch_id' => $main->id])->save();
        $product = $this->product($business, stock: 10, salePrice: 100);

        StockMovement::query()->create([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'product_id' => $product->id,
            'type' => 'manual',
            'quantity' => 1,
            'note' => 'Movimiento branch A',
            'created_by' => $user->id,
        ]);
        StockMovement::query()->create([
            'business_id' => $business->id,
            'branch_id' => $other->id,
            'product_id' => $product->id,
            'type' => 'manual',
            'quantity' => 1,
            'note' => 'Movimiento branch B',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('products.stock-history', $product))
            ->assertOk()
            ->assertSee('Movimiento branch A')
            ->assertDontSee('Movimiento branch B');

        $this->actingAs($user)
            ->post(route('branches.active'), ['branch_id' => $other->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get(route('products.stock-history', $product))
            ->assertOk()
            ->assertSee('Movimiento branch B')
            ->assertDontSee('Movimiento branch A');
    }

    public function test_transfer_validates_available_stock_after_reservations(): void
    {
        [$business, $user] = $this->tenant(modules: ['branches', 'inventory', 'credits'], role: 'owner', enableCredits: true);
        TenantSetting::query()->where('business_id', $business->id)->update(['use_branches' => true]);
        $main = BranchInventory::defaultBranch($business->id);
        $other = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
        ]);
        $user->forceFill(['current_branch_id' => $main->id])->save();
        $product = $this->product($business, stock: 5, salePrice: 100);
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente',
            'doc_type' => 'NIT',
            'doc_number' => '123',
        ]);
        $receipt = CreditReceipt::query()->create([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_doc_type' => 'NIT',
            'customer_doc_number' => '123',
            'receipt_number' => 1,
            'status' => 'pending',
            'subtotal' => 300,
            'total' => 300,
            'pending_total' => 300,
        ]);
        CreditReceiptLine::query()->create([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'credit_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 3,
            'qty_reserved' => 3,
            'qty_pending' => 3,
            'unit_price' => 100,
            'line_total' => 300,
            'pending_total' => 300,
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->from(route('inventory.transfers.create'))
            ->post(route('inventory.transfers.store'), [
                'from_branch_id' => $main->id,
                'to_branch_id' => $other->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 3,
                ]],
            ])
            ->assertSessionHasErrors(['items' => 'No hay suficiente stock disponible para trasladar.']);
    }

    public function test_purchase_payment_method_controls_cash_register_usage(): void
    {
        [$business, $user] = $this->tenant(modules: ['purchases', 'cash_register'], role: 'owner');
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->openCashRegister($business, $user);

        $this->actingAs($user)
            ->post(route('purchases.store'), [
                'supplier' => ['name' => 'Proveedor test'],
                'payment_method' => 'bank_transfer',
                'paid_from_cash' => true,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_cost' => 50,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $purchase = Purchase::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('bank_transfer', $purchase->payment_method);
        $this->assertFalse((bool) $purchase->paid_from_cash);
        $this->assertDatabaseMissing('cash_movements', ['type' => 'purchase_cash', 'reference_id' => $purchase->id]);
    }

    public function test_purchase_cash_from_register_requires_open_cash_register(): void
    {
        [$business, $user] = $this->tenant(modules: ['purchases', 'cash_register'], role: 'owner');
        $product = $this->product($business, stock: 10, salePrice: 100);

        $this->actingAs($user)
            ->from(route('purchases.create'))
            ->post(route('purchases.store'), [
                'supplier' => ['name' => 'Proveedor test'],
                'payment_method' => 'cash',
                'paid_from_cash' => true,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_cost' => 50,
                ]],
            ])
            ->assertSessionHasErrors('cash_register');
    }

    public function test_fel_print_uses_internal_receipt_format_without_fetching_digifact_document(): void
    {
        [$business, $user] = $this->tenant(country: 'GT', modules: ['pos', 'fel_gt'], role: 'owner');
        $this->felSettings($business);

        $sale = Sale::create([
            'business_id' => $business->id,
            'customer_name' => 'Consumidor Final',
            'customer_doc_type' => 'CF',
            'customer_doc_number' => 'CF',
            'customer_address' => 'Ciudad',
            'total' => 100,
            'payment_method' => 'cash',
            'document_type' => 'invoice',
            'status' => 'completed',
            'certification_status' => 'certified',
            'fel_uuid' => '851D92FD-166F-4CFE-925C-ADC0ABB4260D',
            'fel_series' => '851D92FD',
            'fel_number' => '376392958',
            'fel_certified_at' => now(),
            'created_by' => $user->id,
        ]);

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldNotReceive('getDocumentContent');
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        TenantSetting::query()->where('business_id', $business->id)->update(['receipt_format' => 'document']);

        $this->actingAs($user)
            ->get(route('sales.fel-document', $sale))
            ->assertOk()
            ->assertViewIs('sales.fel-document')
            ->assertSee('Documento Tributario Electrónico')
            ->assertSee('851D92FD-166F-4CFE-925C-ADC0ABB4260D')
            ->assertSee('QRService/api/QR', false);

        TenantSetting::query()->where('business_id', $business->id)->update(['receipt_format' => 'ticket']);

        $this->actingAs($user)
            ->get(route('sales.fel-document', $sale))
            ->assertOk()
            ->assertViewIs('sales.fel-ticket')
            ->assertSee('FACTURA ELECTRÓNICA FEL');
    }

    public function test_pos_prewarm_refreshes_missing_fel_token_silently(): void
    {
        [$business, $user] = $this->tenant(country: 'GT', modules: ['pos', 'fel_gt']);
        $this->felSettings($business);

        Http::fake([
            '*login/get_token' => Http::response(['Token' => 'prewarmed-token'], 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('sales.fel.prewarm-token'))
            ->assertOk()
            ->assertJson(['prewarmed' => true, 'token_source' => 'prewarmed']);

        $this->assertSame('prewarmed-token', TenantFelSetting::query()->where('business_id', $business->id)->firstOrFail()->token);
        Http::assertSentCount(1);
    }

    public function test_pos_prewarm_does_not_refresh_a_valid_cached_fel_token(): void
    {
        [$business, $user] = $this->tenant(country: 'GT', modules: ['pos', 'fel_gt']);
        $this->felSettings($business);
        TenantFelSetting::query()->where('business_id', $business->id)->firstOrFail()->update([
            'token' => 'already-valid',
            'token_expires_at' => now()->addMinutes(10),
        ]);

        Http::fake();

        $this->actingAs($user)
            ->postJson(route('sales.fel.prewarm-token'))
            ->assertOk()
            ->assertJson(['prewarmed' => false, 'token_source' => 'cached']);

        Http::assertNothingSent();
    }

    public function test_tenant_users_cannot_access_company_settings_or_branch_management(): void
    {
        [$business, $owner] = $this->tenant(modules: ['branches'], role: 'owner');
        $branch = BranchInventory::defaultBranch($business->id);

        $this->actingAs($owner)->get('/settings/company')->assertNotFound();
        $this->actingAs($owner)->post('/settings/company')->assertNotFound();
        $this->actingAs($owner)->get('/branches')->assertNotFound();
        $this->actingAs($owner)->get('/branches/create')->assertNotFound();
        $this->actingAs($owner)->get('/branches/'.$branch->id.'/edit')->assertNotFound();
        $this->actingAs($owner)->put('/branches/'.$branch->id)->assertNotFound();
        $this->actingAs($owner)->delete('/branches/'.$branch->id)->assertNotFound();
    }

    public function test_tenant_user_management_remains_available_for_owner(): void
    {
        [, $owner] = $this->tenant(role: 'owner');

        $this->actingAs($owner)
            ->get(route('users.index'))
            ->assertOk();
    }

    public function test_super_admin_can_manage_tenant_branches_from_internal_area(): void
    {
        [$business] = $this->tenant(modules: ['branches']);
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->get(route('super-admin.tenants.branches', $business))
            ->assertOk();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.tenants.branches.store', $business), [
                'name' => 'Sucursal Norte',
                'code' => 'NORTE',
                'address' => 'Zona 1',
                'phone' => '5555-0000',
                'is_active' => true,
            ])
            ->assertRedirect();

        $branch = $business->branches()->where('code', 'NORTE')->firstOrFail();

        $this->actingAs($superAdmin)
            ->put(route('super-admin.tenants.branches.update', [$business, $branch]), [
                'name' => 'Sucursal Norte Actualizada',
                'code' => 'NORTE',
                'address' => 'Zona 2',
                'phone' => '5555-1111',
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'business_id' => $business->id,
            'name' => 'Sucursal Norte Actualizada',
            'is_active' => false,
        ]);
    }

    public function test_credit_receipt_reserves_stock_without_creating_sale_payment_or_digifact(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);

        Http::fake();

        $this->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('credits.receipts.store'), $this->creditPayload($product, 3))
            ->assertRedirect(route('sales.create'));

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseHas('credit_receipts', [
            'business_id' => $business->id,
            'receipt_number' => 1,
            'status' => 'pending',
            'total' => 300,
            'pending_total' => 300,
        ]);
        $this->assertDatabaseHas('credit_receipt_lines', [
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'qty_pending' => 3,
            'status' => 'pending',
        ]);

        $this->assertSame(10.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
        $this->assertSame(3, StockAvailability::reservedStock($product, null, BranchInventory::defaultBranch($business->id)->id));
        $this->assertSame(7.0, StockAvailability::availableStock($product, null, BranchInventory::defaultBranch($business->id)->id));
        Http::assertNothingSent();
    }

    public function test_credit_receipt_rejects_final_consumer_customer(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);

        $payload = $this->creditPayload($product, 1);
        $payload['customer']['doc_type'] = 'CF';
        $payload['customer']['doc_number'] = 'CF';

        $this->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('credits.receipts.store'), $payload)
            ->assertSessionHasErrors('customer.doc_number');

        $this->assertDatabaseCount('credit_receipts', 0);
    }

    public function test_credit_line_cancellation_releases_reserved_stock_without_deleting_line(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], role: 'owner', enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);

        $this->actingAs($user)
            ->post(route('credits.receipts.store'), $this->creditPayload($product, 4))
            ->assertRedirect();

        $line = CreditReceiptLine::query()->firstOrFail();

        $this->actingAs($user)
            ->delete(route('credits.lines.cancel', $line), ['reason' => 'Cliente desistió'])
            ->assertRedirect();

        $line->refresh();
        $this->assertSame('cancelled', $line->status);
        $this->assertSame(0, $line->qty_pending);
        $this->assertSame(4, $line->qty_cancelled);
        $this->assertDatabaseHas('credit_receipt_lines', ['id' => $line->id]);
        $this->assertSame(0, StockAvailability::reservedStock($product, null, BranchInventory::defaultBranch($business->id)->id));
    }

    public function test_credit_invoice_selection_creates_normal_sale_and_reduces_pending_credit_line(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register', 'credits'], role: 'owner', enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);

        $this->actingAs($user)
            ->post(route('credits.receipts.store'), $this->creditPayload($product, 2))
            ->assertRedirect();

        $line = CreditReceiptLine::query()->firstOrFail();
        $this->openCashRegister($business, $user);

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload(
                $product,
                quantity: 2,
                total: 200,
                itemOverrides: ['credit_line_id' => $line->id],
            ))
            ->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertSame(2, $line->qty_invoiced);
        $this->assertSame(0, $line->qty_pending);
        $this->assertSame('invoiced', $line->status);
        $this->assertDatabaseHas('credit_receipt_line_invoice', [
            'credit_receipt_line_id' => $line->id,
            'quantity' => 2,
            'amount' => 200,
        ]);
        $this->assertSame(8.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
    }

    public function test_credit_permissions_are_enforced(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], role: 'stock_manager', enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);

        $this->actingAs($user)
            ->post(route('credits.receipts.store'), $this->creditPayload($product, 1))
            ->assertForbidden();

        [$otherBusiness, $owner] = $this->tenant(modules: ['pos', 'credits'], role: 'owner', enableCredits: true);
        $otherProduct = $this->product($otherBusiness, stock: 10, salePrice: 100);
        $this->actingAs($owner)
            ->post(route('credits.receipts.store'), $this->creditPayload($otherProduct, 1))
            ->assertRedirect();

        $line = CreditReceiptLine::query()->where('business_id', $otherBusiness->id)->firstOrFail();
        $owner->roles()->detach();
        Permissions::assignRole($owner->refresh(), 'cashier');

        $this->actingAs($owner)
            ->post(route('credits.invoice-selection'), ['line_ids' => [$line->id]])
            ->assertForbidden();
    }

    public function test_credit_transfer_to_existing_nit_does_not_call_digifact(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], role: 'owner', enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->actingAs($user)->post(route('credits.receipts.store'), $this->creditPayload($product, 1))->assertRedirect();
        $from = Customer::query()->where('business_id', $business->id)->where('doc_number', '57289085')->firstOrFail();
        $to = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente existente',
            'doc_type' => 'NIT',
            'doc_number' => '999999',
            'country' => 'GT',
        ]);

        Http::fake();

        $this->actingAs($user)
            ->post(route('credits.customers.transfer', $from), [
                'to_customer_doc_number' => '999-999',
                'reason' => 'Cambio de NIT',
            ])
            ->assertRedirect(route('credits.customers.show', $to));

        Http::assertNothingSent();
        $this->assertDatabaseHas('credit_receipts', [
            'business_id' => $business->id,
            'customer_id' => $to->id,
            'customer_name' => 'Cliente existente',
        ]);
        $this->assertSame('existing', CreditCustomerTransfer::query()->firstOrFail()->metadata['target_customer_source']);
    }

    public function test_credit_transfer_to_new_valid_nit_uses_digifact_and_creates_customer(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits', 'fel_gt'], role: 'owner', enableCredits: true);
        $this->felSettings($business);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->actingAs($user)->post(route('credits.receipts.store'), $this->creditPayload($product, 1))->assertRedirect();
        $from = Customer::query()->where('business_id', $business->id)->where('doc_number', '57289085')->firstOrFail();

        Http::fake([
            '*login/get_token' => Http::response(['Token' => 'test-token'], 200),
            '*Shared*' => Http::response([
                'REQUEST_DATA' => [['Respuesta' => 1, 'Codigo' => 1]],
                'RESPONSE' => [[
                    'NIT' => '1234567',
                    'NOMBRE' => 'CLIENTE DIGIFACT',
                    'Direccion' => 'ZONA 1',
                    'DEPARTAMENTO' => 'GUATEMALA',
                    'MUNICIPIO' => 'GUATEMALA',
                ]],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('credits.customers.transfer', $from), [
                'to_customer_doc_number' => '1234567',
                'reason' => 'Cambio validado',
            ])
            ->assertRedirect();

        $to = Customer::query()->where('business_id', $business->id)->where('doc_number', '1234567')->firstOrFail();
        $this->assertSame('CLIENTE DIGIFACT', $to->name);
        $this->assertSame('ZONA 1', $to->address);
        $this->assertSame('GUATEMALA', $to->department);
        $this->assertSame('GUATEMALA', $to->municipality);
        $this->assertDatabaseHas('credit_receipts', ['customer_id' => $to->id]);
        $this->assertSame('digifact_created', CreditCustomerTransfer::query()->firstOrFail()->metadata['target_customer_source']);
    }

    public function test_credit_transfer_blocks_cf_and_unresolved_nit_with_readable_error(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits', 'fel_gt'], role: 'owner', enableCredits: true);
        $this->felSettings($business);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->actingAs($user)->post(route('credits.receipts.store'), $this->creditPayload($product, 1))->assertRedirect();
        $from = Customer::query()->where('business_id', $business->id)->where('doc_number', '57289085')->firstOrFail();

        $this->actingAs($user)
            ->from(route('credits.customers.show', $from))
            ->post(route('credits.customers.transfer', $from), [
                'to_customer_doc_number' => 'CF',
                'reason' => 'No permitido',
            ])
            ->assertSessionHasErrors('to_customer_doc_number');

        Http::fake([
            '*login/get_token' => Http::response(['Token' => 'test-token'], 200),
            '*Shared*' => Http::response(['REQUEST_DATA' => [['Codigo' => 0, 'Mensaje' => 'No encontrado']], 'RESPONSE' => []], 200),
        ]);

        $this->actingAs($user)
            ->from(route('credits.customers.show', $from))
            ->post(route('credits.customers.transfer', $from), [
                'to_customer_doc_number' => '1111111',
                'reason' => 'No encontrado',
            ])
            ->assertSessionHasErrors(['to_customer_doc_number' => 'No se pudo validar el NIT. Verifica el número e inténtalo nuevamente.']);
    }

    public function test_user_without_credit_transfer_permission_gets_403(): void
    {
        [$business, $owner] = $this->tenant(modules: ['pos', 'credits'], role: 'owner', enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->actingAs($owner)->post(route('credits.receipts.store'), $this->creditPayload($product, 1))->assertRedirect();
        $from = Customer::query()->where('business_id', $business->id)->where('doc_number', '57289085')->firstOrFail();

        $owner->roles()->detach();
        Permissions::assignRole($owner->refresh(), 'cashier');

        $this->actingAs($owner)
            ->post(route('credits.customers.transfer', $from), [
                'to_customer_doc_number' => '999999',
                'reason' => 'Sin permiso',
            ])
            ->assertForbidden();
    }

    public function test_fel_payload_uses_default_branch_establishment_when_branches_are_disabled(): void
    {
        [$business] = $this->tenant(country: 'GT', modules: ['fel_gt'], allowInvoices: true);
        $settings = $this->felSettings($business);
        BranchInventory::defaultBranchForBusiness($business)->update([
            'fel_establishment_code' => '10',
            'fel_establishment_name' => 'Sucursal Principal FEL',
            'fel_address' => '1 avenida 1-01',
            'fel_postal_code' => '01010',
            'fel_municipality' => 'Guatemala',
            'fel_department' => 'Guatemala',
            'fel_country' => 'GT',
        ]);

        $payload = app(DigifactNucJsonBuilder::class)->buildInvoicePayload(
            $this->invoiceSale($business),
            $settings->refresh(),
        );

        $this->assertSame('Blunk Test', $payload['Seller']['Name']);
        $this->assertSame('10', $payload['Seller']['BranchInfo']['Code']);
        $this->assertSame('Sucursal Principal FEL', $payload['Seller']['BranchInfo']['Name']);
        $this->assertSame('1 avenida 1-01', $payload['Seller']['BranchInfo']['AddressInfo']['Address']);
    }

    public function test_fel_payload_uses_active_sale_branch_establishment_when_branches_are_enabled(): void
    {
        [$business, $user] = $this->tenant(country: 'GT', modules: ['fel_gt', 'branches'], role: 'admin', allowInvoices: true);
        TenantSetting::query()->where('business_id', $business->id)->update(['use_branches' => true]);
        $settings = $this->felSettings($business);

        BranchInventory::defaultBranchForBusiness($business)->update([
            'fel_establishment_code' => '1',
            'fel_establishment_name' => 'Establecimiento A',
            'fel_address' => 'Direccion A',
            'fel_postal_code' => '01001',
            'fel_municipality' => 'Guatemala',
            'fel_department' => 'Guatemala',
            'fel_country' => 'GT',
        ]);
        $branchB = Branch::create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
            'fel_establishment_code' => '2',
            'fel_establishment_name' => 'Establecimiento B',
            'fel_address' => 'Direccion B',
            'fel_postal_code' => '02002',
            'fel_municipality' => 'Mixco',
            'fel_department' => 'Guatemala',
            'fel_country' => 'GT',
        ]);

        $user->forceFill(['current_branch_id' => $branchB->id])->save();
        $this->actingAs($user);
        $payload = app(DigifactNucJsonBuilder::class)
            ->buildInvoicePayload($this->invoiceSale($business, $branchB), $settings->refresh());

        $this->assertSame('Blunk Test', $payload['Seller']['Name']);
        $this->assertSame('2', $payload['Seller']['BranchInfo']['Code']);
        $this->assertSame('Establecimiento B', $payload['Seller']['BranchInfo']['Name']);
        $this->assertSame('Direccion B', $payload['Seller']['BranchInfo']['AddressInfo']['Address']);
    }

    public function test_fel_certification_is_blocked_when_branch_establishment_data_is_missing(): void
    {
        [$business] = $this->tenant(country: 'GT', modules: ['fel_gt'], allowInvoices: true);
        $settings = $this->felSettings($business);
        $settings->update([
            'establishment_code' => null,
            'establishment_name' => null,
            'establishment_address' => null,
            'establishment_postal_code' => null,
            'establishment_municipality' => null,
            'establishment_department' => null,
            'establishment_country' => 'GT',
        ]);
        BranchInventory::defaultBranchForBusiness($business)->update([
            'fel_establishment_code' => null,
            'fel_establishment_name' => null,
            'fel_address' => null,
        ]);

        $this->expectException(FelException::class);
        $this->expectExceptionMessage('Faltan datos del establecimiento FEL. Configura código, nombre y dirección del establecimiento.');

        app(DigifactNucJsonBuilder::class)->buildInvoicePayload($this->invoiceSale($business), $settings->refresh());
    }

    public function test_fel_phrase_renderer_maps_visible_phrases_and_skips_unmapped(): void
    {
        $phrases = FelPhraseRenderer::visiblePhrases([
            ['phrase_type' => '1', 'scenario_code' => '1'],
            ['phrase_type' => '1', 'scenario_code' => '2'],
            ['phrase_type' => '1', 'scenario_code' => '3', 'resolution_number' => 'SAT-123', 'resolution_date' => '2026-05-31'],
            ['phrase_type' => '2', 'scenario_code' => '1'],
            ['phrase_type' => '3', 'scenario_code' => '1'],
            ['phrase_type' => '99', 'scenario_code' => '99'],
        ]);

        $this->assertSame([
            'SUJETO A PAGOS TRIMESTRALES',
            'SUJETO A RETENCIÓN DEFINITIVA',
            'SUJETO A PAGO DIRECTO (SAT-123 - 31/05/2026)',
            'AGENTE DE RETENCIÓN DEL IVA',
            'NO GENERA DERECHO A CRÉDITO FISCAL',
        ], $phrases);
    }

    public function test_real_credit_sale_creates_receivable_and_deducts_stock_without_cash_movement(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $payload = $this->salePayload($product, quantity: 2, total: 200, customer: [
            'name' => 'Cliente crédito real',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
            'address' => 'Ciudad',
        ]);
        $payload['payment_condition'] = 'credit';
        $payload['payments'] = [];

        $this->actingAs($user)->post(route('sales.store'), $payload)->assertSessionHasNoErrors();

        $sale = Sale::query()->latest('id')->firstOrFail();
        $this->assertTrue($sale->is_credit_sale);
        $this->assertSame('unpaid', $sale->payment_status);
        $this->assertSame('200.00', $sale->credit_balance);
        $this->assertSame(8.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseHas('customer_credit_accounts', ['business_id' => $business->id, 'customer_id' => $sale->customer_id, 'current_balance' => 200]);
        $this->assertDatabaseHas('customer_account_movements', ['sale_id' => $sale->id, 'type' => 'charge', 'direction' => 'debit', 'amount' => 200]);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_credit_sale_rejects_blocked_customer_and_credit_limit(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $customer = Customer::create(['business_id' => $business->id, 'name' => 'Cliente limitado', 'doc_type' => 'NIT', 'doc_number' => '1234567', 'country' => 'GT']);
        CustomerCreditAccount::create(['business_id' => $business->id, 'customer_id' => $customer->id, 'credit_limit' => 50, 'current_balance' => 0]);
        $payload = $this->salePayload($product, quantity: 1, total: 100, customer: [
            'id' => $customer->id, 'name' => $customer->name, 'doc_type' => 'NIT', 'doc_number' => $customer->doc_number,
        ]);
        $payload['payment_condition'] = 'credit';
        $payload['payments'] = [];

        $this->actingAs($user)->post(route('sales.store'), $payload)
            ->assertSessionHasErrors('payment_condition');
        $this->assertDatabaseCount('sales', 0);

        $customer->creditAccount()->update(['credit_limit' => null, 'is_blocked' => true]);
        $this->actingAs($user)->post(route('sales.store'), $payload)
            ->assertSessionHasErrors('payment_condition');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_non_cash_credit_payment_reduces_balance_and_allocates_oldest_sale(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $payload = $this->salePayload($product, quantity: 2, total: 200, customer: [
            'name' => 'Cliente abono',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
        ]);
        $payload['payment_condition'] = 'credit';
        $payload['payments'] = [];
        $this->actingAs($user)->post(route('sales.store'), $payload)->assertSessionHasNoErrors();
        $sale = Sale::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('credits.payments.store'), [
            'customer_id' => $sale->customer_id,
            'amount' => 75,
            'payment_method' => 'bank_transfer',
            'reference' => 'TRX-1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('125.00', $sale->refresh()->credit_balance);
        $this->assertSame('partial', $sale->payment_status);
        $this->assertSame('125.00', $sale->customer->creditAccount->current_balance);
        $payment = CustomerCreditPayment::query()->firstOrFail();
        $this->assertDatabaseHas('customer_credit_payment_allocations', ['payment_id' => $payment->id, 'sale_id' => $sale->id, 'amount' => 75]);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_cash_credit_payment_requires_open_register_and_cancellation_reverses_it(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits', 'cash_register'], enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $payload = $this->salePayload($product, quantity: 1, total: 100, customer: [
            'name' => 'Cliente efectivo',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
        ]);
        $payload['payment_condition'] = 'credit';
        $payload['payments'] = [];
        $this->actingAs($user)->post(route('sales.store'), $payload)->assertSessionHasNoErrors();
        $sale = Sale::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('credits.payments.store'), [
            'customer_id' => $sale->customer_id, 'amount' => 100, 'payment_method' => 'cash',
        ])->assertSessionHasErrors('cash_register');

        $this->openCashRegister($business, $user);
        $this->actingAs($user)->post(route('credits.payments.store'), [
            'customer_id' => $sale->customer_id, 'amount' => 100, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();
        $payment = CustomerCreditPayment::query()->firstOrFail();
        $this->assertDatabaseHas('cash_movements', ['type' => 'credit_payment_cash', 'amount' => 100]);
        $this->assertSame('0.00', $sale->customer->creditAccount->fresh()->current_balance);

        Permissions::assignDirectPermissions($user, [Permissions::CREDITS_PAYMENTS_CANCEL]);
        $this->actingAs($user)->post(route('credits.payments.cancel', $payment))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $payment->refresh()->status);
        $this->assertSame('100.00', $sale->customer->creditAccount->fresh()->current_balance);
        $this->assertSame('unpaid', $sale->refresh()->payment_status);
        $this->assertDatabaseHas('cash_movements', ['type' => 'credit_payment_cash_cancel', 'amount' => -100]);
    }

    public function test_credit_reservation_does_not_create_receivable_charge(): void
    {
        [$business, $user] = $this->tenant(modules: ['credits'], enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);

        $this->actingAs($user)->post(route('credits.receipts.store'), $this->creditPayload($product, 2))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('customer_credit_accounts', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    public function test_credit_invoice_fel_failure_rolls_back_sale_stock_and_receivable(): void
    {
        [$business, $user] = $this->tenant(
            country: 'GT',
            modules: ['pos', 'credits', 'fel_gt'],
            allowInvoices: true,
            enableCredits: true,
        );
        $this->felSettings($business);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $customer = Customer::create([
            'business_id' => $business->id,
            'name' => 'Cliente FEL credito',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
            'address' => 'Ciudad',
            'country' => 'GT',
            'name_locked' => true,
            'tax_lookup_verified_at' => now(),
        ]);

        $digifact = Mockery::mock(DigifactInvoiceService::class);
        $digifact->shouldReceive('certifySale')->once()->andThrow(new FelException('Digifact rechazo la factura.'));
        $digifact->shouldReceive('recordSaleRequestTiming')->once();
        $this->app->instance(DigifactInvoiceService::class, $digifact);

        $payload = $this->salePayload($product, quantity: 2, total: 200, documentType: 'invoice', customer: [
            'id' => $customer->id,
            'name' => 'Cliente FEL credito',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
            'address' => 'Ciudad',
            'name_locked' => true,
            'tax_lookup_verified_at' => now()->toDateTimeString(),
        ]);
        $payload['payment_condition'] = 'credit';
        $payload['payments'] = [];

        $this->actingAs($user)
            ->from(route('sales.create'))
            ->post(route('sales.store'), $payload)
            ->assertRedirect(route('sales.create'))
            ->assertSessionHasErrors('document_type');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertDatabaseCount('customer_credit_accounts', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
        $reconciliation = FelReconciliationRequest::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertNull($reconciliation->sale_id);
        $this->assertSame('pending', $reconciliation->status);
        $this->assertStringStartsWith('BLUNK-'.$business->id.'-', $reconciliation->internal_reference);
        $this->assertSame(10.0, (float) ProductBranchStock::query()
            ->where('business_id', $business->id)
            ->where('product_id', $product->id)
            ->value('stock'));
    }

    public function test_user_without_credit_sales_permission_cannot_create_credit_sale(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        $user->roles()->detach();
        Permissions::assignDirectPermissions($user, [Permissions::POS_SELL]);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $payload = $this->salePayload($product, quantity: 1, total: 100, customer: [
            'name' => 'Cliente sin permiso',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
        ]);
        $payload['payment_condition'] = 'credit';
        $payload['payments'] = [];

        $this->actingAs($user)->post(route('sales.store'), $payload)->assertForbidden();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_user_without_credit_payment_permission_cannot_record_payment(): void
    {
        [$business, $user] = $this->tenant(modules: ['credits'], enableCredits: true);
        $user->roles()->detach();
        Permissions::assignDirectPermissions($user, [Permissions::CREDITS_VIEW]);
        $customer = Customer::create(['business_id' => $business->id, 'name' => 'Cliente pago', 'doc_type' => 'NIT', 'doc_number' => '57289085']);
        CustomerCreditAccount::create(['business_id' => $business->id, 'customer_id' => $customer->id, 'current_balance' => 100]);

        $this->actingAs($user)->post(route('credits.payments.store'), [
            'customer_id' => $customer->id,
            'amount' => 25,
            'payment_method' => 'bank_transfer',
        ])->assertForbidden();
    }

    public function test_credit_sale_requires_nit_customer_and_rejects_cf(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);

        $missingCustomer = $this->salePayload($product, quantity: 1, total: 100);
        $missingCustomer['payment_condition'] = 'credit';
        $missingCustomer['payments'] = [];

        $this->actingAs($user)
            ->post(route('sales.store'), $missingCustomer)
            ->assertSessionHasErrors('customer.doc_number');
        $this->assertDatabaseCount('sales', 0);

        $cfCustomer = $this->salePayload($product, quantity: 1, total: 100, customer: [
            'name' => 'Consumidor Final',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'consumidor_final' => true,
        ]);
        $cfCustomer['payment_condition'] = 'credit';
        $cfCustomer['payments'] = [];

        $this->actingAs($user)
            ->post(route('sales.store'), $cfCustomer)
            ->assertSessionHasErrors('customer.doc_number');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_statement_is_business_scoped(): void
    {
        [, $user] = $this->tenant(modules: ['credits'], enableCredits: true);
        [$otherBusiness] = $this->tenant(modules: ['credits'], enableCredits: true);
        $otherCustomer = Customer::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Otro tenant',
            'doc_type' => 'NIT',
            'doc_number' => '9999999',
        ]);

        $this->actingAs($user)
            ->get(route('credits.accounts.statement', $otherCustomer))
            ->assertForbidden();
    }

    public function test_statement_movements_are_branch_scoped(): void
    {
        [$business, $user] = $this->tenant(modules: ['credits', 'branches'], role: 'owner', enableCredits: true);
        TenantSetting::query()->where('business_id', $business->id)->update(['use_branches' => true]);
        $branchA = BranchInventory::defaultBranchForBusiness($business);
        $branchB = Branch::create([
            'business_id' => $business->id,
            'name' => 'Sucursal B',
            'code' => 'B',
            'is_active' => true,
        ]);
        $user->forceFill(['current_branch_id' => $branchA->id])->save();
        $customer = Customer::create(['business_id' => $business->id, 'name' => 'Cliente sucursal', 'doc_type' => 'NIT', 'doc_number' => '57289085']);
        $account = CustomerCreditAccount::create(['business_id' => $business->id, 'customer_id' => $customer->id, 'current_balance' => 200]);

        CustomerAccountMovement::create([
            'business_id' => $business->id,
            'branch_id' => $branchA->id,
            'customer_id' => $customer->id,
            'customer_credit_account_id' => $account->id,
            'type' => 'charge',
            'direction' => 'debit',
            'description' => 'Movimiento A',
            'amount' => 100,
            'balance_after' => 100,
            'created_by' => $user->id,
        ]);
        CustomerAccountMovement::create([
            'business_id' => $business->id,
            'branch_id' => $branchB->id,
            'customer_id' => $customer->id,
            'customer_credit_account_id' => $account->id,
            'type' => 'charge',
            'direction' => 'debit',
            'description' => 'Movimiento B',
            'amount' => 100,
            'balance_after' => 200,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('credits.accounts.statement', $customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Credits/Statement')
                ->has('movements.data', 1)
                ->where('movements.data.0.description', 'Movimiento A')
            );
    }

    public function test_credit_payment_print_route_works(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $payload = $this->salePayload($product, quantity: 1, total: 100, customer: [
            'name' => 'Cliente recibo',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
        ]);
        $payload['payment_condition'] = 'credit';
        $payload['payments'] = [];
        $this->actingAs($user)->post(route('sales.store'), $payload)->assertSessionHasNoErrors();
        $sale = Sale::query()->firstOrFail();

        $this->actingAs($user)->post(route('credits.payments.store'), [
            'customer_id' => $sale->customer_id,
            'amount' => 25,
            'payment_method' => 'bank_transfer',
        ])->assertSessionHasNoErrors();
        $payment = CustomerCreditPayment::query()->firstOrFail();

        $this->actingAs($user)
            ->get(route('credits.payments.print', $payment))
            ->assertOk()
            ->assertSee('RECIBO DE ABONO')
            ->assertSee('AB-');
    }

    public function test_credit_payment_print_route_requires_print_or_payment_view_permission(): void
    {
        [, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        $payment = $this->creditPaymentForPrint($user);

        $user->roles()->detach();
        $user->directPermissions()->sync([]);

        $this->actingAs($user)
            ->get(route('credits.payments.print', $payment))
            ->assertForbidden();
    }

    public function test_credit_payment_print_route_allows_print_or_payment_view_permission(): void
    {
        [, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        $payment = $this->creditPaymentForPrint($user);

        $user->roles()->detach();
        Permissions::assignDirectPermissions($user, [Permissions::CREDITS_PRINT]);

        $this->actingAs($user)
            ->get(route('credits.payments.print', $payment))
            ->assertOk()
            ->assertSee('RECIBO DE ABONO');

        Permissions::assignDirectPermissions($user, [Permissions::CREDITS_PAYMENTS_VIEW]);

        $this->actingAs($user)
            ->get(route('credits.payments.print', $payment))
            ->assertOk()
            ->assertSee('RECIBO DE ABONO');
    }

    public function test_credit_payment_allocates_oldest_unpaid_sales_first(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $customerData = ['name' => 'Cliente FIFO', 'doc_type' => 'NIT', 'doc_number' => '57289085'];

        $first = $this->salePayload($product, quantity: 1, total: 100, customer: $customerData);
        $first['payment_condition'] = 'credit';
        $first['payments'] = [];
        $this->actingAs($user)->post(route('sales.store'), $first)->assertSessionHasNoErrors();
        $firstSale = Sale::query()->latest('id')->firstOrFail();

        $second = $this->salePayload($product, quantity: 2, total: 200, customer: ['id' => $firstSale->customer_id, ...$customerData]);
        $second['payment_condition'] = 'credit';
        $second['payments'] = [];
        $this->actingAs($user)->post(route('sales.store'), $second)->assertSessionHasNoErrors();
        $secondSale = Sale::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('credits.payments.store'), [
            'customer_id' => $firstSale->customer_id,
            'amount' => 150,
            'payment_method' => 'bank_transfer',
        ])->assertSessionHasNoErrors();
        $payment = CustomerCreditPayment::query()->firstOrFail();

        $this->assertSame('paid', $firstSale->refresh()->payment_status);
        $this->assertSame('0.00', $firstSale->credit_balance);
        $this->assertSame('partial', $secondSale->refresh()->payment_status);
        $this->assertSame('150.00', $secondSale->credit_balance);
        $this->assertDatabaseHas('customer_credit_payment_allocations', [
            'payment_id' => $payment->id,
            'sale_id' => $firstSale->id,
            'amount' => 100,
        ]);
        $this->assertDatabaseHas('customer_credit_payment_allocations', [
            'payment_id' => $payment->id,
            'sale_id' => $secondSale->id,
            'amount' => 50,
        ]);
    }

    public function test_credit_sale_with_payments_cannot_be_cancelled(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        Permissions::assignDirectPermissions($user, [Permissions::SALES_CANCEL]);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $payload = $this->salePayload($product, quantity: 1, total: 100, customer: [
            'name' => 'Cliente anular',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
        ]);
        $payload['payment_condition'] = 'credit';
        $payload['payments'] = [];
        $this->actingAs($user)->post(route('sales.store'), $payload)->assertSessionHasNoErrors();
        $sale = Sale::query()->firstOrFail();

        $this->actingAs($user)->post(route('credits.payments.store'), [
            'customer_id' => $sale->customer_id,
            'amount' => 10,
            'payment_method' => 'bank_transfer',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('sales.cancel', $sale), ['reason' => 'Error de prueba'])
            ->assertSessionHasErrors('reason');
        $this->assertSame('completed', $sale->refresh()->status);
        $this->assertSame('90.00', $sale->credit_balance);
    }

    public function test_credit_reservation_invoiced_as_credit_sale_creates_receivable_charge(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits'], enableCredits: true);
        Permissions::assignDirectPermissions($user, [Permissions::CREDITS_INVOICE]);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->actingAs($user)->post(route('credits.receipts.store'), $this->creditPayload($product, 2))->assertSessionHasNoErrors();
        $line = CreditReceiptLine::query()->firstOrFail();

        $payload = $this->salePayload($product, quantity: 2, total: 200, customer: [
            'name' => 'Cliente crÃ©dito',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
        ], itemOverrides: ['credit_line_id' => $line->id]);
        $payload['payment_condition'] = 'credit';
        $payload['payments'] = [];

        $this->actingAs($user)->post(route('sales.store'), $payload)->assertSessionHasNoErrors();
        $sale = Sale::query()->firstOrFail();

        $this->assertTrue($sale->is_credit_sale);
        $this->assertDatabaseHas('customer_account_movements', ['sale_id' => $sale->id, 'type' => 'charge', 'amount' => 200]);
        $this->assertSame(0, CreditReceiptLine::query()->firstOrFail()->qty_pending);
    }

    public function test_credit_reservation_invoiced_as_paid_sale_does_not_create_receivable_charge(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'credits', 'cash_register'], enableCredits: true);
        Permissions::assignDirectPermissions($user, [Permissions::CREDITS_INVOICE]);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $this->actingAs($user)->post(route('credits.receipts.store'), $this->creditPayload($product, 2))->assertSessionHasNoErrors();
        $line = CreditReceiptLine::query()->firstOrFail();
        $this->openCashRegister($business, $user);

        $payload = $this->salePayload($product, quantity: 2, total: 200, customer: [
            'name' => 'Cliente crÃ©dito',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
        ], itemOverrides: ['credit_line_id' => $line->id]);

        $this->actingAs($user)->post(route('sales.store'), $payload)->assertSessionHasNoErrors();

        $sale = Sale::query()->firstOrFail();
        $this->assertFalse($sale->is_credit_sale);
        $this->assertDatabaseMissing('customer_account_movements', ['sale_id' => $sale->id, 'type' => 'charge']);
        $this->assertSame(0, CreditReceiptLine::query()->firstOrFail()->qty_pending);
    }

    public function test_fel_reconciliation_found_without_local_sale_requires_manual_review(): void
    {
        [$business, $user] = $this->tenant(country: 'GT', modules: ['fel_gt'], role: 'owner', allowInvoices: true);
        $this->felSettings($business);
        $request = FelReconciliationRequest::create([
            'business_id' => $business->id,
            'internal_reference' => 'BLUNK-'.$business->id.'-999',
            'issued_date' => now(),
            'provider' => 'digifact',
            'environment' => 'test',
            'status' => 'pending',
        ]);

        $service = Mockery::mock(\App\Services\Fel\Providers\Digifact\DigifactReconciliationService::class);
        $service->shouldReceive('findByInternalReference')->once()->andReturn([
            'found' => true,
            'authNumber' => 'UUID-FOUND',
            'serial' => '123',
            'batch' => 'A',
            'raw' => ['Autorizacion' => 'UUID-FOUND'],
        ]);
        $this->app->instance(\App\Services\Fel\Providers\Digifact\DigifactReconciliationService::class, $service);

        $this->actingAs($user)
            ->post(route('fel.reconciliation.check', $request))
            ->assertSessionHasErrors('reconciliation');

        $this->assertSame('found', $request->refresh()->status);
        $this->assertStringContainsString('venta local no existe', $request->last_error);
    }

    public function test_fel_reconciliation_not_found_marks_request_without_creating_local_records(): void
    {
        [$business, $user] = $this->tenant(country: 'GT', modules: ['fel_gt'], role: 'owner', allowInvoices: true);
        $this->felSettings($business);
        $request = FelReconciliationRequest::create([
            'business_id' => $business->id,
            'internal_reference' => 'BLUNK-'.$business->id.'-NOTFOUND',
            'issued_date' => now(),
            'provider' => 'digifact',
            'environment' => 'test',
            'status' => 'pending',
        ]);

        $service = Mockery::mock(\App\Services\Fel\Providers\Digifact\DigifactReconciliationService::class);
        $service->shouldReceive('findByInternalReference')->once()->andReturn([
            'found' => false,
            'raw' => [],
        ]);
        $this->app->instance(\App\Services\Fel\Providers\Digifact\DigifactReconciliationService::class, $service);

        $this->actingAs($user)
            ->post(route('fel.reconciliation.check', $request))
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame('not_found', $request->status);
        $this->assertSame(1, $request->attempts);
        $this->assertNotNull($request->checked_at);
        $this->assertNull($request->last_error);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    public function test_fel_reconciliation_provider_error_increments_attempts_and_keeps_pending(): void
    {
        [$business, $user] = $this->tenant(country: 'GT', modules: ['fel_gt'], role: 'owner', allowInvoices: true);
        $this->felSettings($business);
        $request = FelReconciliationRequest::create([
            'business_id' => $business->id,
            'internal_reference' => 'BLUNK-'.$business->id.'-ERROR',
            'issued_date' => now(),
            'provider' => 'digifact',
            'environment' => 'test',
            'status' => 'pending',
        ]);

        $service = Mockery::mock(\App\Services\Fel\Providers\Digifact\DigifactReconciliationService::class);
        $service->shouldReceive('findByInternalReference')->once()->andThrow(new FelException('Servicio Digifact no disponible.'));
        $this->app->instance(\App\Services\Fel\Providers\Digifact\DigifactReconciliationService::class, $service);

        $this->actingAs($user)
            ->post(route('fel.reconciliation.check', $request))
            ->assertSessionHasErrors('reconciliation');

        $request->refresh();
        $this->assertSame('pending', $request->status);
        $this->assertSame(1, $request->attempts);
        $this->assertSame('Servicio Digifact no disponible.', $request->last_error);
        $this->assertNotNull($request->checked_at);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('electronic_documents', 0);
        $this->assertDatabaseCount('customer_account_movements', 0);
    }

    public function test_fel_reconciliation_found_with_local_credit_sale_certifies_and_creates_missing_ar_charge(): void
    {
        [$business, $user] = $this->tenant(country: 'GT', modules: ['fel_gt', 'credits'], role: 'owner', allowInvoices: true, enableCredits: true);
        $this->felSettings($business);
        $customer = Customer::create(['business_id' => $business->id, 'name' => 'Cliente conciliado', 'doc_type' => 'NIT', 'doc_number' => '57289085']);
        $sale = Sale::create([
            'business_id' => $business->id,
            'business_number' => 1,
            'branch_id' => BranchInventory::defaultBranchForBusiness($business)->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_doc_type' => 'NIT',
            'customer_doc_number' => $customer->doc_number,
            'total' => 100,
            'subtotal_before_discount' => 100,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'credit_balance' => 100,
            'is_credit_sale' => true,
            'document_type' => 'invoice',
            'status' => 'completed',
            'created_by' => $user->id,
            'fel_internal_reference' => 'BLUNK-'.$business->id.'-LOCAL',
        ]);
        $document = ElectronicDocument::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'document_type' => 'invoice',
            'status' => 'unknown',
            'created_by' => $user->id,
        ]);
        $sale->update(['electronic_document_id' => $document->id]);
        $request = FelReconciliationRequest::create([
            'business_id' => $business->id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'internal_reference' => $sale->fel_internal_reference,
            'issued_date' => now(),
            'provider' => 'digifact',
            'environment' => 'test',
            'status' => 'pending',
        ]);

        $service = Mockery::mock(\App\Services\Fel\Providers\Digifact\DigifactReconciliationService::class);
        $service->shouldReceive('findByInternalReference')->once()->andReturn([
            'found' => true,
            'authNumber' => 'UUID-FOUND',
            'serial' => '123',
            'batch' => 'A',
            'raw' => ['Autorizacion' => 'UUID-FOUND', 'Serial' => '123', 'Batch' => 'A'],
        ]);
        $this->app->instance(\App\Services\Fel\Providers\Digifact\DigifactReconciliationService::class, $service);

        $this->actingAs($user)
            ->post(route('fel.reconciliation.check', $request))
            ->assertSessionHasNoErrors();

        $this->assertSame('resolved', $request->refresh()->status);
        $this->assertSame('certified', $sale->refresh()->certification_status);
        $this->assertSame('UUID-FOUND', $sale->fel_uuid);
        $this->assertDatabaseHas('customer_account_movements', ['sale_id' => $sale->id, 'type' => 'charge', 'amount' => 100]);
    }

    public function test_pos_props_expose_negative_stock_policy_and_zero_available_product(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos'], allowNegativeStock: true);
        $this->product($business, stock: 0, salePrice: 100);

        $this->actingAs($user)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sales/POS')
                ->where('allow_negative_stock', true)
                ->where('products.0.available_stock', fn ($value) => (float) $value === 0.0)
            );

        $source = file_get_contents(resource_path('js/Pages/Sales/POS.tsx'));
        $this->assertStringContainsString('const disabledByStock = !allow_negative_stock && outOfStock;', $source);
        $this->assertStringContainsString('disabled={disabledByStock || processing}', $source);
    }

    public function test_negative_stock_policy_controls_pos_sales(): void
    {
        [$business, $user] = $this->tenant(modules: ['pos', 'cash_register'], allowNegativeStock: false);
        $product = $this->product($business, stock: 1, salePrice: 100);
        $this->openCashRegister($business, $user);

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($product, quantity: 2, total: 200))
            ->assertSessionHasErrors('items');
        $this->assertSame(1.0, (float) ProductBranchStock::query()->where('product_id', $product->id)->value('stock'));

        [$businessAllowed, $userAllowed] = $this->tenant(modules: ['pos', 'cash_register'], allowNegativeStock: true);
        $productAllowed = $this->product($businessAllowed, stock: 1, salePrice: 100);
        $this->openCashRegister($businessAllowed, $userAllowed);

        $this->actingAs($userAllowed)
            ->post(route('sales.store'), $this->salePayload($productAllowed, quantity: 2, total: 200))
            ->assertSessionHasNoErrors();
        $this->assertSame(-1.0, (float) ProductBranchStock::query()->where('product_id', $productAllowed->id)->value('stock'));
    }

    public function test_negative_stock_policy_controls_transfers(): void
    {
        [$business, $user] = $this->tenant(modules: ['branches', 'inventory'], role: 'owner', allowNegativeStock: false);
        TenantSetting::query()->where('business_id', $business->id)->update(['use_branches' => true]);
        $from = BranchInventory::defaultBranchForBusiness($business);
        $to = Branch::create(['business_id' => $business->id, 'name' => 'Destino', 'code' => 'D', 'is_active' => true]);
        $product = $this->product($business, stock: 1, salePrice: 100);

        $this->actingAs($user)->post(route('inventory.transfers.store'), [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertSessionHasErrors(['items' => 'No hay suficiente stock disponible para trasladar.']);

        [$businessAllowed, $userAllowed] = $this->tenant(modules: ['branches', 'inventory'], role: 'owner', allowNegativeStock: true);
        TenantSetting::query()->where('business_id', $businessAllowed->id)->update(['use_branches' => true]);
        $fromAllowed = BranchInventory::defaultBranchForBusiness($businessAllowed);
        $toAllowed = Branch::create(['business_id' => $businessAllowed->id, 'name' => 'Destino', 'code' => 'D', 'is_active' => true]);
        $productAllowed = $this->product($businessAllowed, stock: 1, salePrice: 100);

        $this->actingAs($userAllowed)->post(route('inventory.transfers.store'), [
            'from_branch_id' => $fromAllowed->id,
            'to_branch_id' => $toAllowed->id,
            'items' => [['product_id' => $productAllowed->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(-1.0, (float) ProductBranchStock::query()->where('product_id', $productAllowed->id)->where('branch_id', $fromAllowed->id)->value('stock'));
        $this->assertSame(2.0, (float) ProductBranchStock::query()->where('product_id', $productAllowed->id)->where('branch_id', $toAllowed->id)->value('stock'));
    }

    public function test_negative_stock_policy_controls_credit_reservations(): void
    {
        [$business, $user] = $this->tenant(modules: ['credits'], enableCredits: true, allowNegativeStock: false);
        $product = $this->product($business, stock: 1, salePrice: 100);

        $this->actingAs($user)
            ->post(route('credits.receipts.store'), $this->creditPayload($product, 2))
            ->assertSessionHasErrors('items');

        [$businessAllowed, $userAllowed] = $this->tenant(modules: ['credits'], enableCredits: true, allowNegativeStock: true);
        $productAllowed = $this->product($businessAllowed, stock: 1, salePrice: 100);

        $this->actingAs($userAllowed)
            ->post(route('credits.receipts.store'), $this->creditPayload($productAllowed, 2))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, StockAvailability::reservedStock($productAllowed, null, BranchInventory::defaultBranch($businessAllowed->id)->id));
        $this->assertSame(-1.0, StockAvailability::availableStock($productAllowed, null, BranchInventory::defaultBranch($businessAllowed->id)->id));
    }

    public function test_negative_stock_policy_controls_stock_outputs(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory'], role: 'stock_manager', allowNegativeStock: false);
        $product = $this->product($business, stock: 1, salePrice: 100);

        $this->actingAs($user)->post(route('stock.quick.store'), [
            'product_id' => $product->id,
            'type' => 'exit',
            'quantity' => 2,
            'note' => 'Salida prueba',
        ])->assertSessionHasErrors('quantity');

        [$businessAllowed, $userAllowed] = $this->tenant(modules: ['inventory'], role: 'stock_manager', allowNegativeStock: true);
        $productAllowed = $this->product($businessAllowed, stock: 1, salePrice: 100);

        $this->actingAs($userAllowed)->post(route('stock.quick.store'), [
            'product_id' => $productAllowed->id,
            'type' => 'exit',
            'quantity' => 2,
            'note' => 'Salida prueba',
        ])->assertSessionHasNoErrors();
        $this->assertSame(-1.0, (float) ProductBranchStock::query()->where('product_id', $productAllowed->id)->value('stock'));
    }

    public function test_inventory_and_low_stock_reports_show_negative_stock(): void
    {
        [$business, $user] = $this->tenant(modules: ['inventory', 'reports'], role: 'owner', allowNegativeStock: true);
        $product = $this->product($business, stock: -2, salePrice: 100);

        $this->actingAs($user)
            ->get(route('reports.inventory', ['product_name' => $product->name]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('rows.data.0.stock', fn ($value) => (float) $value === -2.0)
                ->where('rows.data.0.available', fn ($value) => (float) $value === -2.0)
            );

        $this->actingAs($user)
            ->get(route('reports.low-stock', ['product_search' => $product->name]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Generic')
                ->where('rows.data.0.available', fn ($value) => (float) $value === -2.0)
            );
    }

    private function tenant(
        string $country = 'GT',
        array $modules = [],
        string $role = 'cashier',
        bool $allowManualPrice = false,
        bool $allowReceipts = true,
        bool $allowInvoices = false,
        bool $enableCredits = false,
        string $pricingScope = 'global',
        bool $allowNegativeStock = false,
    ): array
    {
        $business = Business::create([
            'name' => 'Blunk Test',
            'slug' => 'blunk-test-'.uniqid(),
            'currency' => 'GTQ',
            'country' => $country,
            'is_active' => true,
        ]);

        TenantSetting::create([
            'business_id' => $business->id,
            'use_product_images' => true,
            'max_users' => 10,
            'use_branches' => false,
            'products_shared_across_branches' => true,
            'pricing_scope' => $pricingScope,
            'allow_manual_price' => $allowManualPrice,
            'remember_last_customer_product_price' => false,
            'enable_credit_sales' => $enableCredits,
            'allow_negative_stock' => $allowNegativeStock,
            'allow_receipts' => $allowReceipts,
            'allow_invoices' => $allowInvoices,
        ]);

        foreach (array_unique($modules) as $module) {
            TenantModule::create([
                'business_id' => $business->id,
                'module' => $module,
                'is_enabled' => true,
                'enabled_at' => now(),
            ]);
        }

        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => $role,
            'is_active' => true,
            'is_super_admin' => false,
        ]);
        Permissions::assignRole($user, $role);

        return [$business, $user];
    }

    private function product(Business $business, string $name = 'Producto test', int $stock = 10, float $salePrice = 100): Product
    {
        $product = Product::create([
            'business_id' => $business->id,
            'name' => $name,
            'code' => 'SKU-'.uniqid(),
            'cost_price' => round($salePrice / 2, 2),
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

        PriceType::query()
            ->where('business_id', $business->id)
            ->update(['is_default' => false]);

        $priceType = PriceType::updateOrCreate(
            ['business_id' => $business->id, 'name' => 'General'],
            ['is_default' => true, 'is_active' => true],
        );

        ProductPrice::query()->updateOrCreate(
            ['business_id' => $business->id, 'product_id' => $product->id, 'price_type_id' => $priceType->id],
            ['price' => $salePrice, 'is_active' => true],
        );

        return $product;
    }

    private function productRecord(Business $business, array $overrides = []): array
    {
        return [
            'business_id' => $business->id,
            'name' => $overrides['name'] ?? 'Producto existente',
            'code' => $overrides['code'] ?? 'CODE-'.uniqid(),
            'barcode' => $overrides['barcode'] ?? null,
            'cost_price' => $overrides['cost_price'] ?? 50,
            'sale_price' => $overrides['sale_price'] ?? 100,
            'stock' => $overrides['stock'] ?? 0,
            'min_stock' => $overrides['min_stock'] ?? 0,
            'is_active' => $overrides['is_active'] ?? true,
        ];
    }

    private function productFormPayload(array $overrides = []): array
    {
        return [
            'name' => $overrides['name'] ?? 'Producto nuevo',
            'code' => $overrides['code'] ?? 'NEW-'.uniqid(),
            'barcode' => $overrides['barcode'] ?? null,
            'cost_price' => $overrides['cost_price'] ?? 50,
            'sale_price' => $overrides['sale_price'] ?? 100,
            'stock' => $overrides['stock'] ?? 0,
            'min_stock' => $overrides['min_stock'] ?? 0,
            'location' => $overrides['location'] ?? null,
            'is_active' => $overrides['is_active'] ?? true,
            'category_name' => $overrides['category_name'] ?? null,
            'prices' => $overrides['prices'] ?? [],
        ];
    }

    private function openCashRegister(Business $business, User $user): void
    {
        CashRegisterSession::create([
            'business_id' => $business->id,
            'branch_id' => BranchInventory::activeBranch($business->id)->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);
    }

    private function invoiceSale(Business $business, ?Branch $branch = null): Sale
    {
        $branch ??= BranchInventory::defaultBranchForBusiness($business);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $sale = Sale::create([
            'business_id' => $business->id,
            'business_number' => 1,
            'branch_id' => $branch->id,
            'customer_name' => 'Consumidor Final',
            'customer_doc_type' => 'CF',
            'customer_doc_number' => 'CF',
            'customer_address' => 'Ciudad',
            'customer_country' => 'GT',
            'subtotal_before_discount' => 100,
            'discount_amount' => 0,
            'total' => 100,
            'payment_method' => 'cash',
            'document_type' => 'invoice',
            'status' => 'completed',
        ]);

        SaleItem::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 100,
            'unit_cost' => 50,
            'total_before_discount' => 100,
            'total_after_discount' => 100,
            'total' => 100,
            'discount_amount' => 0,
        ]);

        return $sale->refresh();
    }

    private function felSettings(Business $business, bool $enabled = true): TenantFelSetting
    {
        $settings = TenantFelSetting::create([
            'business_id' => $business->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'enabled' => $enabled,
            'issuer_tax_id' => '5888492',
            'username' => 'TESTUSER',
            'password' => 'secret',
            'test_base_url' => 'https://testnucgt.digifact.com/api',
            'production_base_url' => null,
            'establishment_code' => '1',
            'establishment_name' => 'Casa Matriz',
            'establishment_address' => 'Ciudad',
            'establishment_postal_code' => '01001',
            'establishment_municipality' => 'Guatemala',
            'establishment_department' => 'Guatemala',
            'establishment_country' => 'GT',
            'affiliate_type' => 'GEN',
        ]);

        TenantFelPhrase::create([
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

        BranchInventory::defaultBranchForBusiness($business)->update([
            'fel_establishment_code' => '1',
            'fel_establishment_name' => 'Casa Matriz',
            'fel_address' => 'Ciudad',
            'fel_postal_code' => '01001',
            'fel_municipality' => 'Guatemala',
            'fel_department' => 'Guatemala',
            'fel_country' => 'GT',
        ]);

        return $settings;
    }

    private function salePayload(
        Product $product,
        int|string $quantity,
        float $total,
        string $documentType = 'receipt',
        ?array $customer = null,
        ?array $items = null,
        ?array $discount = null,
        array $itemOverrides = [],
    ): array {
        $items ??= [
            ['product' => $product, 'quantity' => $quantity],
        ];

        return [
            'document_type' => $documentType,
            'customer' => $customer,
            'items' => array_map(function (array $item) use ($itemOverrides) {
                /** @var Product $product */
                $product = $item['product'];

                return [
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    ...$itemOverrides,
                ];
            }, $items),
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => $total,
                ],
            ],
            'discount' => $discount,
        ];
    }

    private function creditPayload(Product $product, int $quantity): array
    {
        return [
            'customer' => [
                'name' => 'Cliente crédito',
                'doc_type' => 'NIT',
                'doc_number' => '57289085',
                'address' => 'Ciudad',
                'phone' => '5555-5555',
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => (float) $product->sale_price,
                ],
            ],
            'note' => 'Reserva a crédito',
        ];
    }

    private function creditPaymentForPrint(User $user): CustomerCreditPayment
    {
        $business = Business::query()->findOrFail($user->business_id);
        $product = $this->product($business, stock: 10, salePrice: 100);
        $payload = $this->salePayload($product, quantity: 1, total: 100, customer: [
            'name' => 'Cliente recibo',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
        ]);
        $payload['payment_condition'] = 'credit';
        $payload['payments'] = [];

        $this->actingAs($user)->post(route('sales.store'), $payload)->assertSessionHasNoErrors();
        $sale = Sale::query()
            ->where('business_id', $business->id)
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($user)->post(route('credits.payments.store'), [
            'customer_id' => $sale->customer_id,
            'amount' => 25,
            'payment_method' => 'bank_transfer',
        ])->assertSessionHasNoErrors();

        return CustomerCreditPayment::query()
            ->where('business_id', $business->id)
            ->latest('id')
            ->firstOrFail();
    }
}
