<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\CreditCustomerTransfer;
use App\Models\CreditReceipt;
use App\Models\CreditReceiptLine;
use App\Models\Customer;
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
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Support\BranchInventory;
use App\Support\Permissions;
use App\Support\StockAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    private function tenant(
        string $country = 'GT',
        array $modules = [],
        string $role = 'cashier',
        bool $allowManualPrice = false,
        bool $allowReceipts = true,
        bool $allowInvoices = false,
        bool $enableCredits = false,
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
            'pricing_scope' => 'global',
            'allow_manual_price' => $allowManualPrice,
            'remember_last_customer_product_price' => false,
            'enable_credit_sales' => $enableCredits,
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

    private function openCashRegister(Business $business, User $user): void
    {
        CashRegisterSession::create([
            'business_id' => $business->id,
            'opened_by' => $user->id,
            'status' => 'open',
            'opening_amount' => 0,
            'expected_cash' => 0,
            'opened_at' => now(),
        ]);
    }

    private function felSettings(Business $business, bool $enabled = true): void
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
}
