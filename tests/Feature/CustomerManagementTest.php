<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerTaxLookup;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permissions::syncDefaults();
    }

    public function test_customer_listing_requires_permission(): void
    {
        [, $user] = $this->tenantUser('stock_manager');

        $this->actingAs($user)
            ->get(route('customers.index'))
            ->assertForbidden();
    }

    public function test_customer_listing_is_tenant_scoped(): void
    {
        [$businessA, $userA] = $this->tenantUser('owner', 'Customer A');
        [$businessB] = $this->tenantUser('owner', 'Customer B');

        Customer::query()->create(['business_id' => $businessA->id, 'name' => 'Cliente propio', 'doc_number' => '111', 'country' => 'GT']);
        Customer::query()->create(['business_id' => $businessB->id, 'name' => 'Cliente ajeno', 'doc_number' => '222', 'country' => 'GT']);

        $this->actingAs($userA)
            ->get(route('customers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Index')
                ->where('customers.data.0.name', 'Cliente propio')
                ->missing('customers.data.1')
            );
    }

    public function test_customer_search_matches_name_nit_and_contact(): void
    {
        [$business, $user] = $this->tenantUser('owner');

        Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Ferreteria Central',
            'commercial_name' => 'Central',
            'contact_name' => 'Mariela Lopez',
            'doc_number' => '5728-9085',
            'phone' => '5555-0000',
            'country' => 'GT',
        ]);

        foreach (['Ferreteria', '57289085', 'Mariela'] as $search) {
            $this->actingAs($user)
                ->get(route('customers.index', ['search' => $search]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Customers/Index')
                    ->where('customers.data.0.name', 'Ferreteria Central')
                );
        }
    }

    public function test_cf_customer_can_update_name_and_general_fields(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Consumidor Final',
            'doc_type' => 'CF',
            'doc_number' => 'C/F',
            'is_final_consumer' => true,
            'country' => 'GT',
        ]);

        $this->actingAs($user)
            ->from(route('customers.edit', $customer))
            ->put(route('customers.update', $customer), [
                'name' => 'Cliente CF actualizado',
                'commercial_name' => 'Tienda CF',
                'contact_name' => 'Encargado',
                'phone' => '5555-1111',
                'address' => 'Zona 1',
                'postal_code' => '01001',
                'department' => 'Guatemala',
                'municipality' => 'Guatemala',
            ])
            ->assertRedirect(route('customers.edit', $customer));

        $customer->refresh();

        $this->assertSame('Cliente CF actualizado', $customer->name);
        $this->assertSame('CF', $customer->doc_number);
        $this->assertSame('Tienda CF', $customer->commercial_name);
        $this->assertFalse((bool) $customer->name_locked);
    }

    public function test_real_nit_customer_cannot_update_nit_or_fiscal_name(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $customer = $this->realNitCustomer($business, ['name_locked' => true, 'tax_lookup_verified_at' => now()]);

        $this->actingAs($user)
            ->from(route('customers.edit', $customer))
            ->put(route('customers.update', $customer), [
                'name' => 'Nombre manual',
                'doc_number' => '999999',
                'commercial_name' => 'Negocio permitido',
            ])
            ->assertSessionHasErrors([
                'name' => 'El nombre fiscal no puede editarse manualmente.',
                'doc_number' => 'El NIT no puede editarse manualmente.',
            ]);

        $customer->refresh();
        $this->assertSame('Cliente SAT', $customer->name);
        $this->assertSame('5728-9085', $customer->doc_number);
    }

    public function test_real_nit_customer_can_update_general_fields_without_changing_fiscal_identity(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $customer = $this->realNitCustomer($business, ['name_locked' => true, 'tax_lookup_verified_at' => now()]);

        $this->actingAs($user)
            ->from(route('customers.edit', $customer))
            ->put(route('customers.update', $customer), [
                'name' => 'Cliente SAT',
                'commercial_name' => 'Comercial permitido',
                'contact_name' => 'Contacto',
                'phone' => '5555-2222',
                'address' => 'Zona 10',
                'postal_code' => '01010',
                'department' => 'Guatemala',
                'municipality' => 'Guatemala',
            ])
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('Cliente SAT', $customer->name);
        $this->assertSame('5728-9085', $customer->doc_number);
        $this->assertSame('Comercial permitido', $customer->commercial_name);
    }

    public function test_refresh_tax_data_updates_old_unverified_nit_customer_from_cache(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $customer = $this->realNitCustomer($business, [
            'name' => 'Cliente viejo',
            'name_locked' => false,
            'tax_lookup_verified_at' => null,
        ]);
        $this->taxLookup($business, '57289085', 'Nombre Oficial SAT');

        $this->actingAs($user)
            ->from(route('customers.edit', $customer))
            ->post(route('customers.refresh-tax-data', $customer))
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('Nombre Oficial SAT', $customer->name);
        $this->assertSame('57289085', $customer->doc_number);
        $this->assertTrue((bool) $customer->name_locked);
        $this->assertNotNull($customer->tax_lookup_verified_at);
    }

    public function test_cf_customer_can_assign_valid_nit_and_becomes_locked(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente CF',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'is_final_consumer' => true,
            'country' => 'GT',
        ]);
        $this->taxLookup($business, '9988771', 'Cliente Validado SAT');

        $this->actingAs($user)
            ->from(route('customers.edit', $customer))
            ->post(route('customers.assign-nit', $customer), ['nit' => '99-88771'])
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('Cliente Validado SAT', $customer->name);
        $this->assertSame('NIT', $customer->doc_type);
        $this->assertSame('9988771', $customer->doc_number);
        $this->assertFalse((bool) $customer->is_final_consumer);
        $this->assertTrue((bool) $customer->name_locked);
        $this->assertNotNull($customer->tax_lookup_verified_at);
    }

    public function test_cf_customer_cannot_assign_duplicate_nit(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $cf = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente CF',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'is_final_consumer' => true,
            'country' => 'GT',
        ]);
        $this->realNitCustomer($business, ['doc_number' => '57289085']);

        $this->actingAs($user)
            ->from(route('customers.edit', $cf))
            ->post(route('customers.assign-nit', $cf), ['nit' => '5728-9085'])
            ->assertSessionHasErrors(['nit' => 'Ya existe un cliente con este NIT.']);

        $this->assertSame('CF', $cf->refresh()->doc_number);
    }

    public function test_cf_customer_is_not_modified_when_assigned_nit_is_invalid(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $cf = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente CF',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'is_final_consumer' => true,
            'country' => 'GT',
        ]);

        $this->actingAs($user)
            ->from(route('customers.edit', $cf))
            ->post(route('customers.assign-nit', $cf), ['nit' => 'C/F'])
            ->assertSessionHasErrors(['nit' => 'El NIT ingresado no es válido.']);

        $this->assertSame('Cliente CF', $cf->refresh()->name);
        $this->assertSame('CF', $cf->doc_number);
    }

    public function test_customer_from_another_tenant_cannot_be_edited_or_viewed(): void
    {
        [, $userA] = $this->tenantUser('owner', 'Tenant A');
        [$businessB] = $this->tenantUser('owner', 'Tenant B');
        $otherCustomer = Customer::query()->create([
            'business_id' => $businessB->id,
            'name' => 'Cliente ajeno',
            'doc_number' => '123',
            'country' => 'GT',
        ]);

        $this->actingAs($userA)
            ->get(route('customers.edit', $otherCustomer))
            ->assertNotFound();

        $this->actingAs($userA)
            ->put(route('customers.update', $otherCustomer), ['name' => 'Hack'])
            ->assertNotFound();
    }

    public function test_permission_catalog_contains_customer_manage_permission(): void
    {
        $this->assertArrayHasKey(Permissions::CUSTOMERS_VIEW, Permissions::catalog());
        $this->assertArrayHasKey(Permissions::CUSTOMERS_CREATE, Permissions::catalog());
        $this->assertArrayHasKey(Permissions::CUSTOMERS_UPDATE, Permissions::catalog());
        $this->assertArrayHasKey(Permissions::CUSTOMERS_MANAGE, Permissions::catalog());
    }

    public function test_navigation_contains_customer_menu_guarded_by_permission(): void
    {
        $source = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.tsx'));

        $this->assertStringContainsString("hasModule('customers') && can('customers.view')", $source);
        $this->assertStringContainsString("label: 'Clientes'", $source);
        $this->assertStringContainsString("route('customers.index')", $source);
    }

    public function test_touched_customer_files_do_not_contain_mojibake(): void
    {
        $files = [
            app_path('Http/Controllers/CustomerController.php'),
            app_path('Support/GuatemalaNitCustomerResolver.php'),
            resource_path('js/Pages/Customers/Index.tsx'),
            resource_path('js/Pages/Customers/Edit.tsx'),
            resource_path('js/Layouts/AuthenticatedLayout.tsx'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            foreach (["\xEF\xBF\xBD", "\xC3\x83", "\xC3\x82", "\xC3\xAF\xC2\xBF\xC2\xBD", "\xC3\xA2\xE2\x82\xAC"] as $badSequence) {
                $this->assertStringNotContainsString($badSequence, $contents, $file);
            }
        }
    }

    private function tenantUser(string $role, string $name = 'Customer Tenant'): array
    {
        $business = Business::query()->create([
            'name' => $name.' '.uniqid(),
            'slug' => str($name)->slug().'-'.uniqid(),
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
            'enable_credit_reservations' => false,
            'reserve_stock_on_credit_reservations' => true,
            'allow_negative_stock' => false,
            'allow_receipts' => true,
            'allow_invoices' => false,
        ]);

        TenantModule::query()->create([
            'business_id' => $business->id,
            'module' => 'customers',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => $role,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        Permissions::assignRole($user, $role);

        return [$business, $user];
    }

    private function realNitCustomer(Business $business, array $overrides = []): Customer
    {
        return Customer::query()->create(array_replace([
            'business_id' => $business->id,
            'name' => 'Cliente SAT',
            'doc_type' => 'NIT',
            'doc_number' => '5728-9085',
            'is_final_consumer' => false,
            'name_locked' => false,
            'tax_lookup_verified_at' => null,
            'country' => 'GT',
        ], $overrides));
    }

    private function taxLookup(Business $business, string $nit, string $name): void
    {
        CustomerTaxLookup::query()->create([
            'business_id' => $business->id,
            'country' => 'GT',
            'doc_type' => 'NIT',
            'doc_number' => $nit,
            'name' => $name,
            'provider' => 'digifact',
            'raw_response' => ['nit' => $nit, 'name' => $name],
            'last_lookup_at' => now(),
        ]);
    }
}
