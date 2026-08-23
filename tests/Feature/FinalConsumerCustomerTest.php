<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Sale;
use App\Support\FinalConsumerAuditor;
use App\Support\CustomerIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class FinalConsumerCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_final_consumer_tax_id_variants(): void
    {
        $this->assertSame('CF', CustomerIdentity::normalizeTaxId(' c / f '));
        $this->assertSame('CF', CustomerIdentity::normalizeTaxId('Consumidor Final'));
        $this->assertSame('57289085', CustomerIdentity::normalizeTaxId('5728-9085'));
    }

    public function test_generic_final_consumer_is_reused_per_business(): void
    {
        $business = Business::query()->create([
            'name' => 'Final Consumer '.uniqid(),
            'slug' => 'final-consumer-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);

        $first = Customer::getOrCreateGenericFinalConsumer($business);
        $second = Customer::getOrCreateGenericFinalConsumer($business);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Customer::query()->where('business_id', $business->id)->count());
        $this->assertSame('CF', $first->normalized_tax_id);
    }

    public function test_personalized_final_consumer_is_not_generic(): void
    {
        $this->assertFalse(CustomerIdentity::isGenericFinalConsumer('CF', 'Juan Perez'));
        $this->assertFalse(CustomerIdentity::isGenericFinalConsumer('CF', 'Consumidor Final', ['phone' => '5555-1234']));
    }

    public function test_database_rejects_duplicate_real_tax_id_after_normalization(): void
    {
        $business = $this->business();
        Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente uno',
            'doc_type' => 'NIT',
            'doc_number' => '5728-9085',
            'country' => 'GT',
        ]);

        $this->expectException(QueryException::class);
        Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente dos',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
            'country' => 'GT',
        ]);
    }

    public function test_audit_and_confirmed_merge_only_process_generic_final_consumers(): void
    {
        $business = $this->business();
        $first = $this->genericFinalConsumer($business);
        $duplicate = $this->genericFinalConsumer($business);
        $personalized = Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente Mostrador',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'country' => 'GT',
            'is_final_consumer' => true,
        ]);
        Sale::query()->create([
            'business_id' => $business->id,
            'customer_id' => $first->id,
            'total' => 5,
            'payment_method' => 'cash',
        ]);
        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'customer_id' => $duplicate->id,
            'total' => 10,
            'payment_method' => 'cash',
        ]);

        $auditor = app(FinalConsumerAuditor::class);
        $audit = $auditor->audit(['business' => $business->id, 'report' => true]);
        $preview = $auditor->merge(['business' => $business->id, 'confirm' => false]);

        $this->assertSame(1, $audit['generic_cf_duplicate_groups']);
        $this->assertSame([$duplicate->id], $preview['duplicate_customer_ids']);
        $this->assertSame($first->id, $preview['canonical_customer_id']);
        $this->assertSame(1, $preview['sales_to_move']);
        $this->assertFileExists($audit['report_path'].'/summary.csv');
        $this->assertNull($sale->fresh()->customer->merged_into_customer_id);

        $result = $auditor->merge(['business' => $business->id, 'confirm' => true]);

        $this->assertSame('confirm', $result['mode']);
        $this->assertSame($first->id, $sale->fresh()->customer_id);
        $this->assertSame($first->id, $duplicate->fresh()->merged_into_customer_id);
        $this->assertNull($personalized->fresh()->merged_into_customer_id);
    }

    public function test_audit_detects_legacy_real_tax_id_duplicates_without_merging_them(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_business_real_tax_id_unique');
        $business = $this->business();
        Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente uno',
            'doc_type' => 'NIT',
            'doc_number' => '5728-9085',
            'country' => 'GT',
        ]);
        Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Cliente dos',
            'doc_type' => 'NIT',
            'doc_number' => '57289085',
            'country' => 'GT',
        ]);

        $result = app(FinalConsumerAuditor::class)->audit(['business' => $business->id]);

        $this->assertSame(1, $result['real_tax_id_duplicate_groups']);
        $this->assertSame(2, $result['real_tax_id_duplicates']);
        $this->assertSame(2, Customer::query()->where('business_id', $business->id)->count());
    }

    public function test_final_consumer_commands_are_registered_and_audit_is_read_only(): void
    {
        $business = $this->business();
        $this->genericFinalConsumer($business);

        $this->artisan('customers:audit-final-consumer', [
            '--business' => $business->id,
            '--dry-run' => true,
            '--report' => true,
        ])->assertSuccessful();

        $this->assertArrayHasKey('customers:audit-final-consumer', Artisan::all());
        $this->assertArrayHasKey('customers:merge-generic-final-consumer', Artisan::all());
        $this->assertSame(1, Customer::query()->where('business_id', $business->id)->count());
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'Final Consumer '.uniqid(),
            'slug' => 'final-consumer-'.uniqid(),
            'currency' => 'GTQ',
            'country' => 'GT',
            'is_active' => true,
        ]);
    }

    private function genericFinalConsumer(Business $business): Customer
    {
        return Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Consumidor Final',
            'doc_type' => 'CF',
            'doc_number' => 'CF',
            'country' => 'GT',
            'is_final_consumer' => true,
        ]);
    }
}
