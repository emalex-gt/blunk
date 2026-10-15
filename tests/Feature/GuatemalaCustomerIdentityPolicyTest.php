<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerTaxLookup;
use App\Support\CustomerIdentity;
use App\Support\GuatemalaCustomerIdentityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GuatemalaCustomerIdentityPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_cf_is_manual_and_never_performs_an_external_lookup(): void
    {
        Http::fake();

        $identity = app(GuatemalaCustomerIdentityPolicy::class)->resolve($this->business(), 'CF', 'c/f');

        $this->assertSame(CustomerIdentity::CF, $identity['doc_type']);
        $this->assertSame(CustomerIdentity::CF, $identity['doc_number']);
        $this->assertNull($identity['name']);
        Http::assertNothingSent();
    }

    public function test_named_cf_uses_the_same_manual_identity_without_an_external_lookup(): void
    {
        Http::fake();

        $identity = app(GuatemalaCustomerIdentityPolicy::class)->resolve($this->business(), 'CF', 'CF');

        $this->assertSame(CustomerIdentity::CF, $identity['doc_type']);
        $this->assertSame(CustomerIdentity::CF, $identity['doc_number']);
        Http::assertNothingSent();
    }

    public function test_nit_uses_the_exact_verified_lookup_identity_and_ignores_a_manual_name(): void
    {
        $business = $this->business();
        $this->cache($business, CustomerIdentity::NIT, '1234567', 'NOMBRE FISCAL NIT');

        $identity = app(GuatemalaCustomerIdentityPolicy::class)->resolve($business, 'NIT', '123-4567');

        $this->assertSame(CustomerIdentity::NIT, $identity['doc_type']);
        $this->assertSame('1234567', $identity['doc_number']);
        $this->assertSame('NOMBRE FISCAL NIT', $identity['name']);
        $this->assertNotNull($identity['tax_lookup_verified_at']);
    }

    public function test_cui_requires_exactly_thirteen_digits(): void
    {
        $this->expectException(ValidationException::class);

        app(GuatemalaCustomerIdentityPolicy::class)->resolve($this->business(), 'CUI', '224925636130');
    }

    public function test_cui_uses_the_exact_verified_lookup_without_fabricating_an_address(): void
    {
        $business = $this->business();
        $cui = '2249256361307';
        $this->cache($business, CustomerIdentity::CUI, $cui, 'NOMBRE OFICIAL CUI');

        $identity = app(GuatemalaCustomerIdentityPolicy::class)->resolve($business, 'CUI', $cui);

        $this->assertSame(CustomerIdentity::CUI, $identity['doc_type']);
        $this->assertSame($cui, $identity['doc_number']);
        $this->assertSame('NOMBRE OFICIAL CUI', $identity['name']);
        $this->assertArrayNotHasKey('address', $identity);
    }

    public function test_an_unchanged_verified_identity_remains_usable_without_an_external_lookup(): void
    {
        $business = $this->business();
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'NOMBRE OFICIAL',
            'country' => 'GT',
            'doc_type' => 'NIT',
            'doc_number' => '1234567',
            'name_locked' => true,
            'tax_lookup_verified_at' => now()->subDay(),
        ]);
        Http::fake();

        $identity = app(GuatemalaCustomerIdentityPolicy::class)->resolve($business, 'NIT', '123-4567', $customer);

        $this->assertSame('NOMBRE OFICIAL', $identity['name']);
        Http::assertNothingSent();
    }

    public function test_verification_for_one_document_cannot_authorize_another_document(): void
    {
        $business = $this->business();
        $customer = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'NOMBRE OFICIAL A',
            'country' => 'GT',
            'doc_type' => 'NIT',
            'doc_number' => '1234567',
            'name_locked' => true,
            'tax_lookup_verified_at' => now(),
        ]);
        Http::fake();

        $this->expectException(ValidationException::class);

        app(GuatemalaCustomerIdentityPolicy::class)->resolve($business, 'NIT', '7654321', $customer);
    }

    public function test_verified_customer_from_another_business_cannot_authorize_the_same_identity(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $customer = Customer::query()->create([
            'business_id' => $businessA->id,
            'name' => 'NOMBRE OFICIAL',
            'country' => 'GT',
            'doc_type' => 'NIT',
            'doc_number' => '1234567',
            'name_locked' => true,
            'tax_lookup_verified_at' => now(),
        ]);
        Http::fake();

        $this->expectException(ValidationException::class);

        app(GuatemalaCustomerIdentityPolicy::class)->resolve($businessB, 'NIT', '1234567', $customer);
    }

    public function test_merged_verified_customer_cannot_be_reused_as_a_valid_identity(): void
    {
        $business = $this->business();
        $replacement = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente reemplazo',
            'country' => 'GT',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
        ]);
        $merged = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'NOMBRE OFICIAL',
            'country' => 'GT',
            'doc_type' => 'NIT',
            'doc_number' => '1234567',
            'name_locked' => true,
            'tax_lookup_verified_at' => now(),
            'merged_into_customer_id' => $replacement->id,
        ]);
        Http::fake();

        $this->expectException(ValidationException::class);

        app(GuatemalaCustomerIdentityPolicy::class)->resolve($business, 'NIT', '1234567', $merged);
    }

    public function test_non_guatemala_business_cannot_use_this_policy(): void
    {
        Http::fake();

        $this->expectException(ValidationException::class);

        try {
            app(GuatemalaCustomerIdentityPolicy::class)->resolve($this->business('AR'), 'CF', 'CF');
        } finally {
            Http::assertNothingSent();
        }
    }

    private function business(string $country = 'GT'): Business
    {
        return Business::query()->create([
            'name' => 'Identidad '.$country.' '.uniqid(),
            'country' => $country,
            'currency' => $country === 'GT' ? 'GTQ' : 'ARS',
            'timezone' => $country === 'GT' ? 'America/Guatemala' : 'America/Argentina/Buenos_Aires',
            'is_active' => true,
        ]);
    }

    private function cache(Business $business, string $docType, string $docNumber, string $name): void
    {
        CustomerTaxLookup::query()->create([
            'business_id' => $business->id,
            'country' => 'GT',
            'doc_type' => $docType,
            'doc_number' => $docNumber,
            'name' => $name,
            'provider' => 'digifact',
            'raw_response' => ['RESPONSE' => []],
            'last_lookup_at' => now(),
        ]);
    }
}
