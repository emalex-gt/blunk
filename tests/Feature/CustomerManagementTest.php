<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerTaxLookup;
use App\Models\TenantModule;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Permissions;
use App\Support\GuatemalaNitCustomerResolver;
use App\Support\TextEncoding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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

    public function test_text_encoding_detects_replacement_character_mojibake(): void
    {
        $this->assertTrue(TextEncoding::hasMojibake('L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ'));
    }

    public function test_text_encoding_detects_double_encoded_mojibake(): void
    {
        $this->assertTrue(TextEncoding::hasMojibake('L'.chr(0xC3).chr(0x83).chr(0xE2).chr(0x80).chr(0x9C).'PEZ'));
    }

    public function test_text_encoding_does_not_flag_clean_accented_name(): void
    {
        $this->assertFalse(TextEncoding::hasMojibake('LÓPEZ'));
    }

    public function test_text_encoding_does_not_flag_clean_ascii_name(): void
    {
        $this->assertFalse(TextEncoding::hasMojibake('LOPEZ,LOPEZ,EMANUEL,ALEJANDRO'));
    }

    public function test_text_encoding_flags_specific_damaged_nit_names(): void
    {
        $this->assertTrue(TextEncoding::hasMojibake('L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ,L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ,EMANUEL,ALEJANDRO'));
        $this->assertTrue(TextEncoding::hasMojibake('L'.chr(0xC3).chr(0x83).chr(0xE2).chr(0x80).chr(0x9C).'PEZ,L'.chr(0xC3).chr(0x83).chr(0xE2).chr(0x80).chr(0x9C).'PEZ,EMANUEL,ALEJANDRO'));
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

    public function test_refresh_tax_data_skips_cache_and_uses_external_lookup(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $customer = $this->realNitCustomer($business, [
            'name' => 'Cliente viejo',
            'name_locked' => false,
            'tax_lookup_verified_at' => null,
        ]);
        $this->felSettings($business);
        $this->taxLookup($business, '57289085', 'Nombre Cache Viejo');
        $this->fakeDigifactNitResponse('57289085', 'Nombre Oficial SAT');

        $this->actingAs($user)
            ->from(route('customers.edit', $customer))
            ->post(route('customers.refresh-tax-data', $customer))
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('Nombre Oficial SAT', $customer->name);
        $this->assertSame('57289085', $customer->doc_number);
        $this->assertTrue((bool) $customer->name_locked);
        $this->assertNotNull($customer->tax_lookup_verified_at);

        $this->assertSame('Nombre Oficial SAT', CustomerTaxLookup::query()->where('business_id', $business->id)->where('doc_number', '57289085')->value('name'));
        Http::assertSentCount(1);
    }

    public function test_refresh_tax_data_replaces_mojibake_name_from_external_even_when_cache_is_bad(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $customer = $this->realNitCustomer($business, [
            'name' => 'L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ,L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ,EMANUEL,ALEJANDRO',
            'doc_number' => '57289085',
            'name_locked' => true,
            'tax_lookup_verified_at' => now(),
        ]);
        $this->felSettings($business);
        $this->taxLookup($business, '57289085', 'L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ');
        $this->fakeDigifactNitResponse('57289085', 'LÓPEZ,LÓPEZ,EMANUEL,ALEJANDRO');

        $this->actingAs($user)
            ->from(route('customers.edit', $customer))
            ->post(route('customers.refresh-tax-data', $customer))
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('LÓPEZ,LÓPEZ,EMANUEL,ALEJANDRO', $customer->name);
        $this->assertTrue((bool) $customer->name_locked);
        $this->assertNotNull($customer->tax_lookup_verified_at);
    }

    public function test_refresh_tax_data_replaces_mojibake_name_when_external_returns_clean_ascii_name(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $customer = $this->realNitCustomer($business, [
            'name' => 'L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ,L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ,EMANUEL,ALEJANDRO',
            'doc_number' => '57289085',
            'name_locked' => true,
            'tax_lookup_verified_at' => now(),
        ]);
        $this->felSettings($business);
        $this->taxLookup($business, '57289085', 'L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ');
        $this->fakeDigifactNitResponse('57289085', 'LOPEZ,LOPEZ,EMANUEL,ALEJANDRO');

        $this->actingAs($user)
            ->from(route('customers.edit', $customer))
            ->post(route('customers.refresh-tax-data', $customer))
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('LOPEZ,LOPEZ,EMANUEL,ALEJANDRO', $customer->name);
        $this->assertFalse(TextEncoding::hasMojibake($customer->name));
        $this->assertSame('LOPEZ,LOPEZ,EMANUEL,ALEJANDRO', CustomerTaxLookup::query()->where('business_id', $business->id)->where('doc_number', '57289085')->value('name'));
    }

    public function test_refresh_tax_data_ignores_bad_cache_and_does_not_show_false_success(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $customer = $this->realNitCustomer($business, [
            'name' => 'L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ',
            'doc_number' => '57289085',
            'name_locked' => true,
            'tax_lookup_verified_at' => now(),
        ]);
        $this->felSettings($business);
        $this->taxLookup($business, '57289085', 'L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ');
        $this->fakeDigifactNitResponse('57289085', 'L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ');

        $this->actingAs($user)
            ->from(route('customers.edit', $customer))
            ->post(route('customers.refresh-tax-data', $customer))
            ->assertSessionHasErrors('nit');

        $this->assertSame('L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ', $customer->refresh()->name);
    }

    public function test_lookup_tax_data_preserves_clean_utf8_from_digifact(): void
    {
        [$business] = $this->tenantUser('owner');
        $this->felSettings($business);
        $this->fakeDigifactNitResponse('57289085', 'LÓPEZ,LÓPEZ,EMANUEL,ALEJANDRO');

        $lookup = GuatemalaNitCustomerResolver::lookupTaxData($business, '57289085', allowCache: false);

        $this->assertSame('LÓPEZ,LÓPEZ,EMANUEL,ALEJANDRO', $lookup['name']);
        $this->assertFalse(TextEncoding::hasMojibake($lookup['name']));
    }

    public function test_lookup_tax_data_converts_single_byte_digifact_response_without_mojibake(): void
    {
        [$business] = $this->tenantUser('owner');
        $this->felSettings($business);
        $name = 'L'.chr(0xD3).'PEZ,L'.chr(0xD3).'PEZ,EMANUEL,ALEJANDRO';
        $body = '{"RESPONSE":[{"NIT":"57289085","NOMBRE":"'.$name.'"}]}';

        Http::fake([
            '*' => Http::response($body, 200, ['Content-Type' => 'application/json; charset=ISO-8859-1']),
        ]);

        $lookup = GuatemalaNitCustomerResolver::lookupTaxData($business, '57289085', allowCache: false);

        $this->assertSame('LÓPEZ,LÓPEZ,EMANUEL,ALEJANDRO', $lookup['name']);
        $this->assertFalse(TextEncoding::hasMojibake($lookup['name']));
    }

    public function test_lookup_tax_data_rejects_mojibake_cache_when_not_ignored(): void
    {
        [$business] = $this->tenantUser('owner');
        $this->taxLookup($business, '57289085', 'L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ');

        try {
            GuatemalaNitCustomerResolver::lookupTaxData($business, '57289085', allowCache: true, ignoreBadCache: false);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'No se pudo obtener un nombre fiscal limpio para este NIT. La fuente de validación devolvió datos con caracteres inválidos.',
                $exception->errors()['nit'][0],
            );
        }
    }

    public function test_customer_edit_marks_encoding_issue_in_props(): void
    {
        [$business, $user] = $this->tenantUser('owner');
        $customer = $this->realNitCustomer($business, [
            'name' => 'L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ',
            'name_locked' => true,
            'tax_lookup_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('customers.edit', $customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Edit')
                ->where('customer.has_encoding_issue', true)
                ->where('customer.encoding_issue_fields.0', 'name')
            );
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

    public function test_customers_encoding_audit_command_detects_damaged_customers(): void
    {
        [$business] = $this->tenantUser('owner');
        Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'L'.chr(0xEF).chr(0xBF).chr(0xBD).'PEZ',
            'doc_number' => '57289085',
            'country' => 'GT',
        ]);

        $this->artisan('customers:audit-encoding', ['--business' => $business->id])
            ->assertSuccessful()
            ->expectsOutput('Clientes con posible mojibake: 1');

        $this->assertArrayHasKey('customers:audit-encoding', Artisan::all());
    }

    public function test_touched_customer_files_do_not_contain_mojibake(): void
    {
        $files = [
            app_path('Http/Controllers/CustomerController.php'),
            app_path('Services/Fel/Providers/Digifact/DigifactClient.php'),
            app_path('Support/GuatemalaNitCustomerResolver.php'),
            app_path('Support/TextEncoding.php'),
            resource_path('js/Pages/Customers/Index.tsx'),
            resource_path('js/Pages/Customers/Edit.tsx'),
            resource_path('js/Layouts/AuthenticatedLayout.tsx'),
            base_path('routes/console.php'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            foreach (["\xEF\xBF\xBD", "\xC3\x83", "\xC3\x82", "\xC3\xAF\xC2\xBF\xC2\xBD", "\xC3\xA2\xE2\x82\xAC"] as $badSequence) {
                $this->assertStringNotContainsString($badSequence, $contents, $file);
            }
        }
    }

    public function test_debug_nit_lookup_command_reports_sources_without_modifying_customer_or_cache(): void
    {
        [$business] = $this->tenantUser('owner');
        $this->felSettings($business);
        $customer = $this->realNitCustomer($business, [
            'name' => 'Nombre viejo',
            'doc_number' => '57289085',
        ]);
        $this->taxLookup($business, '57289085', 'Nombre cache');
        $this->fakeDigifactNitResponse('57289085', 'Nombre externo SAT');

        $this->artisan('customers:debug-nit-lookup', ['nit' => '5728-9085', '--business' => $business->id])
            ->assertSuccessful()
            ->expectsOutput('NIT normalizado: 57289085')
            ->expectsOutput('customer.exists: yes')
            ->expectsOutput('customer.name.codepoints: U+004E U+006F U+006D U+0062 U+0072 U+0065 U+0020 U+0076 U+0069 U+0065 U+006A U+006F')
            ->expectsOutput('customer.name.hex_utf8: 4E 6F 6D 62 72 65 20 76 69 65 6A 6F')
            ->expectsOutput('cache.exists: yes')
            ->expectsOutput('cache.name.codepoints: U+004E U+006F U+006D U+0062 U+0072 U+0065 U+0020 U+0063 U+0061 U+0063 U+0068 U+0065')
            ->expectsOutput('external.called: yes')
            ->expectsOutput('external.extracted_name.codepoints: U+004E U+006F U+006D U+0062 U+0072 U+0065 U+0020 U+0065 U+0078 U+0074 U+0065 U+0072 U+006E U+006F U+0020 U+0053 U+0041 U+0054')
            ->expectsOutput('external.extracted_name.hex_utf8: 4E 6F 6D 62 72 65 20 65 78 74 65 72 6E 6F 20 53 41 54')
            ->expectsOutput('raw_body.contains_u_fffd_bytes: no')
            ->expectsOutput('raw_body.contains_literal_u00_escape: no');

        $this->assertSame('Nombre viejo', $customer->refresh()->name);
        $this->assertSame('Nombre cache', CustomerTaxLookup::query()->where('business_id', $business->id)->where('doc_number', '57289085')->value('name'));
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

    private function felSettings(Business $business): TenantFelSetting
    {
        $settings = TenantFelSetting::query()->create([
            'business_id' => $business->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'enabled' => true,
            'issuer_tax_id' => '5888492',
            'username' => 'TESTUSER',
            'password' => 'secret',
            'token' => 'cached-token',
            'token_expires_at' => now()->addHour(),
            'test_base_url' => 'https://digifact.test/api',
            'production_base_url' => null,
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

        return $settings;
    }

    private function fakeDigifactNitResponse(string $nit, string $name): void
    {
        Http::fake([
            '*' => Http::response([
                'RESPONSE' => [
                    [
                        'NIT' => $nit,
                        'NOMBRE' => $name,
                    ],
                ],
            ], 200, ['Content-Type' => 'application/json; charset=UTF-8']),
        ]);
    }
}
