<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Support\CustomerIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerIdentitySemanticsTest extends TestCase
{
    use RefreshDatabase;

    private const SYNTHETIC_CUI = '1234567890123';

    public function test_customer_identity_normalizes_each_guatemala_document_type(): void
    {
        $this->assertSame('NIT', CustomerIdentity::normalizeGuatemalaDocType(' nit '));
        $this->assertSame('57289085', CustomerIdentity::normalizeDocument('NIT', '5728-9085'));
        $this->assertSame(self::SYNTHETIC_CUI, CustomerIdentity::normalizeDocument('CUI', '1234 5678 9012 3'));
        $this->assertSame('CF', CustomerIdentity::normalizeDocument('CF', 'C/F'));
        $this->assertNull(CustomerIdentity::normalizeGuatemalaDocType('DPI-LEGACY'));
    }

    public function test_nit_and_cui_with_same_digits_are_distinct_typed_identities(): void
    {
        $business = $this->business('typed');

        $nit = Customer::findOrCreateByFiscalIdentity($business, [
            'name' => 'Persona NIT',
            'doc_type' => 'NIT',
            'doc_number' => self::SYNTHETIC_CUI,
            'country' => 'GT',
        ]);
        $cui = Customer::findOrCreateByFiscalIdentity($business, [
            'name' => 'Persona CUI',
            'doc_type' => 'CUI',
            'doc_number' => self::SYNTHETIC_CUI,
            'country' => 'GT',
        ]);

        $this->assertNotSame($nit->id, $cui->id);
        $this->assertSame('NIT', $nit->doc_type);
        $this->assertSame('CUI', $cui->doc_type);
        $this->assertSame(2, Customer::query()->where('business_id', $business->id)->count());
    }

    public function test_same_typed_identity_is_reused_but_other_tenant_is_isolated(): void
    {
        $businessA = $this->business('tenant-a');
        $businessB = $this->business('tenant-b');
        $attributes = [
            'name' => 'Persona CUI',
            'doc_type' => 'CUI',
            'doc_number' => self::SYNTHETIC_CUI,
            'country' => 'GT',
        ];

        $first = Customer::findOrCreateByFiscalIdentity($businessA, $attributes);
        $replay = Customer::findOrCreateByFiscalIdentity($businessA, $attributes);
        $otherTenant = Customer::findOrCreateByFiscalIdentity($businessB, $attributes);

        $this->assertSame($first->id, $replay->id);
        $this->assertNotSame($first->id, $otherTenant->id);
    }

    public function test_database_rejects_duplicate_cui_for_same_tenant_and_type(): void
    {
        $business = $this->business('database-unique');
        Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Persona uno',
            'doc_type' => 'CUI',
            'doc_number' => self::SYNTHETIC_CUI,
            'country' => 'GT',
        ]);

        $this->expectException(QueryException::class);
        Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Persona dos',
            'doc_type' => 'CUI',
            'doc_number' => '1234-5678-9012-3',
            'country' => 'GT',
        ]);
    }

    public function test_cui_requires_exactly_thirteen_digits_and_type_is_authoritative(): void
    {
        $business = $this->business('validation');

        foreach (['123456789012', '12345678901234', '12345678901A3'] as $invalid) {
            try {
                Customer::findOrCreateByFiscalIdentity($business, [
                    'name' => 'Persona inválida',
                    'doc_type' => 'CUI',
                    'doc_number' => $invalid,
                    'country' => 'GT',
                ]);
                $this->fail('Expected invalid CUI validation error.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('customer.doc_number', $exception->errors());
            }
        }

        $nit = Customer::findOrCreateByFiscalIdentity($business, [
            'name' => 'Trece dígitos como NIT',
            'doc_type' => 'NIT',
            'doc_number' => self::SYNTHETIC_CUI,
            'country' => 'GT',
        ]);
        $this->assertSame('NIT', $nit->doc_type);
    }

    public function test_invalid_guatemala_document_type_is_rejected(): void
    {
        $business = $this->business('invalid-type');

        $this->expectException(ValidationException::class);
        Customer::findOrCreateByFiscalIdentity($business, [
            'name' => 'Tipo inválido',
            'doc_type' => 'DPI-LEGACY',
            'doc_number' => self::SYNTHETIC_CUI,
            'country' => 'GT',
        ]);
    }

    public function test_existing_nit_and_cf_behavior_remains_canonical(): void
    {
        $business = $this->business('compatibility');
        $nit = Customer::findOrCreateByNormalizedTaxId($business, [
            'name' => 'Persona NIT',
            'doc_type' => 'NIT',
            'doc_number' => '5728-9085',
            'country' => 'GT',
        ]);
        $cf = Customer::getOrCreateGenericFinalConsumer($business);

        $this->assertSame('57289085', $nit->doc_number);
        $this->assertSame('57289085', $nit->normalized_tax_id);
        $this->assertSame('CF', $cf->doc_type);
        $this->assertSame('CF', $cf->doc_number);
        $this->assertSame($cf->id, Customer::getOrCreateGenericFinalConsumer($business)->id);
    }

    private function business(string $suffix): Business
    {
        return Business::query()->create([
            'name' => 'Identity '.$suffix,
            'slug' => 'identity-'.$suffix.'-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
    }
}
