<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\FiscalDocumentIntent;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Models\TenantSetting;
use App\Services\Fel\FelException;
use App\Services\Fel\Providers\Digifact\DigifactClient;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Services\Fel\Providers\Digifact\DigifactNucJsonBuilder;
use App\Services\Fel\Providers\Digifact\DigifactReconciliationService;
use App\Services\Fiscal\FiscalDocumentIntentService;
use App\Support\BranchInventory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DigifactInternalReferenceContractTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_REFERENCE = '550e8400-e29b-41d4-a716-446655440000';

    public function test_new_guatemala_payload_uses_the_nuc_adenda_contract(): void
    {
        [, $sale, $settings] = $this->saleFixture();
        $sale->update(['fel_internal_reference' => self::PROVIDER_REFERENCE]);

        $payload = app(DigifactNucJsonBuilder::class)->buildInvoicePayload($sale->fresh(), $settings);

        $this->assertSame([
            'AdditionalInfo' => [[
                'Code' => self::PROVIDER_REFERENCE,
                'Type' => 'ADENDA',
                'AditionalInfo' => [
                    'Info' => [
                        ['Name' => 'VALIDAR_REFERENCIA_INTERNA', 'Data' => null, 'Value' => 'VALIDAR'],
                        ['Name' => 'NoReferencia', 'Data' => null, 'Value' => 'BLUNK-'.$sale->business_id.'-'.$sale->id],
                    ],
                ],
            ]],
        ], $payload['AdditionalDocumentInfo']);
    }

    public function test_fiscal_intent_persists_one_uuid_before_any_provider_call_and_reuses_it_for_the_same_key(): void
    {
        [, $sale] = $this->saleFixture();
        $frozenReference = null;
        $invoice = Mockery::mock(DigifactInvoiceService::class);
        $invoice->shouldReceive('buildIntentPayload')
            ->once()
            ->withArgs(function (Sale $candidate, string $reference) use ($sale): bool {
                return $candidate->is($sale)
                    && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference) === 1;
            })
            ->andReturnUsing(function (Sale $_sale, string $reference) use (&$frozenReference): array {
                $frozenReference = $reference;

                return [
                    'Header' => ['IssuedDateTime' => '2026-10-29T09:30:00-06:00'],
                    'AdditionalDocumentInfo' => [
                        'AdditionalInfo' => [[
                            'Code' => $reference,
                            'Type' => 'ADENDA',
                            'AditionalInfo' => ['Info' => []],
                        ]],
                    ],
                ];
            });
        $this->app->instance(DigifactInvoiceService::class, $invoice);

        $service = app(FiscalDocumentIntentService::class);
        $first = $service->createForDraftSale(
            $sale,
            ['environment' => 'test'],
            'internal-reference-contract-key',
            null,
        );
        $second = $service->createForDraftSale(
            $sale,
            ['environment' => 'test'],
            'internal-reference-contract-key',
            null,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $first->internal_reference,
        );
        $this->assertSame($first->internal_reference, $frozenReference);
        $this->assertSame($first->internal_reference, $first->fiscal_payload['AdditionalDocumentInfo']['AdditionalInfo'][0]['Code'] ?? null);
    }

    public function test_reconciliation_uses_the_frozen_provider_uuid_and_issue_datetime(): void
    {
        [$business] = $this->saleFixture();
        $issuedAt = '2026-10-29T09:30:00-06:00';
        $intent = FiscalDocumentIntent::query()->create([
            'business_id' => $business->id,
            'country' => 'GT',
            'provider' => 'digifact',
            'environment' => 'test',
            'document_type' => 'invoice',
            'operation_type' => 'sale',
            'internal_reference' => self::PROVIDER_REFERENCE,
            'status' => FiscalDocumentIntent::UNKNOWN,
            'operation_payload' => [],
            'fiscal_payload' => [
                'Header' => ['IssuedDateTime' => $issuedAt],
                'AdditionalDocumentInfo' => [
                    'AdditionalInfo' => [['Code' => self::PROVIDER_REFERENCE]],
                ],
            ],
        ]);

        $reconciliation = Mockery::mock(DigifactReconciliationService::class);
        $reconciliation->shouldReceive('findByInternalReference')
            ->once()
            ->withArgs(fn (Business $actualBusiness, string $reference, string $actualIssuedAt): bool =>
                $actualBusiness->is($business)
                && $reference === self::PROVIDER_REFERENCE
                && $actualIssuedAt === $issuedAt,
            )
            ->andThrow(new FelException('Consulta ambigua.'));
        $this->app->instance(DigifactReconciliationService::class, $reconciliation);

        $resolved = app(FiscalDocumentIntentService::class)->reconcile($intent);

        $this->assertSame(FiscalDocumentIntent::UNKNOWN, $resolved->status);
        $this->assertSame(0, $resolved->negative_reconciliation_count);
    }

    public function test_legacy_reference_is_not_rebuilt_or_certified_as_a_new_uuid(): void
    {
        [, $sale] = $this->saleFixture();
        $sale->update(['fel_internal_reference' => 'BLUNK-'.$sale->business_id.'-'.$sale->id]);

        $this->expectException(FelException::class);
        app(DigifactInvoiceService::class)->buildIntentPayload(
            $sale->fresh(),
            (string) $sale->fel_internal_reference,
        );
    }

    public function test_historical_reference_remains_queryable_without_being_reinterpreted_as_a_uuid(): void
    {
        [$business] = $this->saleFixture();
        $issuedAt = '2026-09-01T09:30:00-06:00';
        $legacyReference = 'FIS-LEGACY-REFERENCE';
        $intent = FiscalDocumentIntent::query()->create([
            'business_id' => $business->id,
            'country' => 'GT',
            'provider' => 'digifact',
            'environment' => 'test',
            'document_type' => 'invoice',
            'operation_type' => 'sale',
            'internal_reference' => $legacyReference,
            'status' => FiscalDocumentIntent::UNKNOWN,
            'operation_payload' => [],
            'fiscal_payload' => ['Header' => ['IssuedDateTime' => $issuedAt]],
        ]);

        $reconciliation = Mockery::mock(DigifactReconciliationService::class);
        $reconciliation->shouldReceive('findByInternalReference')
            ->once()
            ->withArgs(fn (Business $actualBusiness, string $reference, string $actualIssuedAt): bool =>
                $actualBusiness->is($business)
                && $reference === $legacyReference
                && $actualIssuedAt === $issuedAt,
            )
            ->andThrow(new FelException('Consulta ambigua.'));
        $this->app->instance(DigifactReconciliationService::class, $reconciliation);

        $resolved = app(FiscalDocumentIntentService::class)->reconcile($intent);

        $this->assertSame(FiscalDocumentIntent::UNKNOWN, $resolved->status);
        $this->assertSame($legacyReference, $resolved->internal_reference);
    }

    public function test_internal_reference_is_unique_within_business_provider_and_environment(): void
    {
        [$business] = $this->saleFixture();
        $attributes = [
            'business_id' => $business->id,
            'country' => 'GT',
            'provider' => 'digifact',
            'environment' => 'test',
            'document_type' => 'invoice',
            'operation_type' => 'sale',
            'internal_reference' => self::PROVIDER_REFERENCE,
            'status' => FiscalDocumentIntent::PENDING,
            'operation_payload' => [],
            'fiscal_payload' => [],
        ];

        FiscalDocumentIntent::query()->create($attributes + ['idempotency_key' => 'reference-unique-a']);

        $this->expectException(QueryException::class);
        FiscalDocumentIntent::query()->create($attributes + ['idempotency_key' => 'reference-unique-b']);
    }

    public function test_only_a_confirmed_empty_provider_response_is_a_valid_negative_lookup(): void
    {
        [$business] = $this->saleFixture();
        Http::fake(['*Shared*' => Http::response([
            'REQUEST_DATA' => [['Respuesta' => 1, 'Codigo' => 1]],
            'RESPONSE' => [],
        ])]);

        $result = DigifactClient::forBusiness($business)->findDocumentByInternalReference(
            $business->tenantFelSetting,
            self::PROVIDER_REFERENCE,
            '2026-10-29T09:30:00-06:00',
        );

        $this->assertSame([], $result);
        Http::assertSent(fn (Request $request): bool =>
            str_contains($request->url(), 'SHARED_GETDTEINFO_BY_INTERNALID')
            && str_contains($request->url(), self::PROVIDER_REFERENCE),
        );
    }

    public function test_ambiguous_empty_internal_reference_lookup_is_not_treated_as_a_valid_negative(): void
    {
        [$business] = $this->saleFixture();
        Http::fake(['*Shared*' => Http::response([
            'REQUEST_DATA' => [['Respuesta' => 0, 'Codigo' => 0, 'Mensaje' => 'Temporal']],
            'RESPONSE' => [],
        ])]);

        try {
            DigifactClient::forBusiness($business)->findDocumentByInternalReference(
                $business->tenantFelSetting,
                self::PROVIDER_REFERENCE,
                '2026-10-29T09:30:00-06:00',
            );
            $this->fail('Expected an ambiguous empty lookup to be rejected.');
        } catch (FelException) {
            // The provider did not prove that no DTE exists, so reconciliation remains blocked.
        }

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'SHARED_GETDTEINFO_BY_INTERNALID'));
    }

    /** @return array{Business, Sale, TenantFelSetting} */
    private function saleFixture(): array
    {
        $business = Business::query()->create([
            'name' => 'Digifact Internal Reference Test',
            'slug' => 'digifact-internal-reference-'.uniqid(),
            'country' => 'GT',
            'currency' => 'GTQ',
            'timezone' => 'America/Guatemala',
            'is_active' => true,
        ]);
        TenantSetting::query()->create([
            'business_id' => $business->id,
            'max_users' => 10,
            'use_branches' => false,
            'products_shared_across_branches' => true,
        ]);
        $branch = BranchInventory::defaultBranchForBusiness($business);
        $branch->update([
            'fel_establishment_code' => '1',
            'fel_establishment_name' => 'Casa Matriz',
            'fel_address' => 'Ciudad',
            'fel_postal_code' => '01001',
            'fel_municipality' => 'Guatemala',
            'fel_department' => 'Guatemala',
            'fel_country' => 'GT',
        ]);
        $settings = TenantFelSetting::query()->create([
            'business_id' => $business->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'enabled' => true,
            'issuer_tax_id' => '5888492',
            'username' => 'TESTUSER',
            'password' => 'test-password',
            'token' => 'cached-token',
            'token_expires_at' => now()->addHour(),
            'test_base_url' => 'https://testnucgt.digifact.com/api',
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
        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'business_number' => 1,
            'customer_name' => 'Consumidor Final',
            'customer_doc_type' => 'CF',
            'customer_doc_number' => 'CF',
            'customer_address' => 'Ciudad',
            'customer_postal_code' => '01001',
            'customer_municipality' => 'Guatemala',
            'customer_department' => 'Guatemala',
            'customer_country' => 'GT',
            'subtotal_before_discount' => 100,
            'total' => 100,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'amount_paid' => 100,
            'status' => 'completed',
            'document_type' => 'invoice',
        ]);
        SaleItem::query()->create([
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

        return [$business, $sale->fresh(), $settings];
    }
}
