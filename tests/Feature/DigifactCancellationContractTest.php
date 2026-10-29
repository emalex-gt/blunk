<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Services\Fel\FelException;
use App\Services\Fel\FelProviderRejectedException;
use App\Services\Fel\Providers\Digifact\DigifactClient;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Services\Fel\Providers\Digifact\DigifactNucJsonBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigifactCancellationContractTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '5B5BA194-22C5-4377-99D4-B8F86820533D';

    public function test_cancellation_uses_the_cancel_fel_gt_endpoint_and_rest_payload(): void
    {
        [$business, $document] = $this->certifiedDocument();
        $payload = app(DigifactNucJsonBuilder::class)->buildCancellationPayload($document, 'Error en datos del receptor');

        Http::fake(['*CancelFelGT' => Http::response([
            'Codigo' => '1',
            'Autorizacion' => self::UUID,
        ])]);

        DigifactClient::forBusiness($business)->cancel($payload);

        $this->assertSame([
            'Taxid' => '5888492',
            'Autorizacion' => self::UUID,
            'IdReceptor' => 'CF',
            'FechaEmisionDocumentoAnular' => '2026-10-01T09:30:00',
            'MotivoAnulacion' => 'Error en datos del receptor',
            'Username' => 'TESTUSER',
        ], $payload);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://testnucgt.digifact.com/api/CancelFelGT'
                && $request->data() === [
                    'Taxid' => '5888492',
                    'Autorizacion' => self::UUID,
                    'IdReceptor' => 'CF',
                    'FechaEmisionDocumentoAnular' => '2026-10-01T09:30:00',
                    'MotivoAnulacion' => 'Error en datos del receptor',
                    'Username' => 'TESTUSER',
                ]
                && $request->hasHeader('Authorization', 'cached-token');
        });
    }

    public function test_cancellation_preserves_the_original_guatemala_issue_time_after_the_document_is_reloaded(): void
    {
        [, $document] = $this->certifiedDocument();
        $document = $document->fresh(['sale.customer', 'business.tenantFelSetting']);

        $payload = app(DigifactNucJsonBuilder::class)->buildCancellationPayload($document, 'Error en datos del receptor');

        $this->assertSame('2026-10-01T09:30:00', $payload['FechaEmisionDocumentoAnular']);
    }

    public function test_cancellation_is_confirmed_only_when_the_provider_returns_the_same_uuid(): void
    {
        [, $document] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response(['Codigo' => '1', 'Autorizacion' => self::UUID])]);

        app(DigifactInvoiceService::class)->cancelElectronicDocument($document, 'Error en datos del receptor');

        $this->assertSame('cancelled', $document->refresh()->status);
        $this->assertNotNull($document->cancelled_at);
    }

    public function test_cancellation_with_a_different_uuid_is_uncertain_not_confirmed(): void
    {
        [, $document] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response(['Codigo' => '1', 'Autorizacion' => 'OTHER-UUID'])]);

        try {
            app(DigifactInvoiceService::class)->cancelElectronicDocument($document, 'Error en datos del receptor');
            $this->fail('Expected an unconfirmed cancellation exception.');
        } catch (FelException) {
            $this->assertSame('cancellation_unknown', $document->refresh()->status);
        }
    }

    public function test_explicit_cancellation_rejection_is_recorded_as_rejected(): void
    {
        [, $document] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response(['Codigo' => '0', 'Mensaje' => 'Anulación rechazada'])]);

        try {
            app(DigifactInvoiceService::class)->cancelElectronicDocument($document, 'Error en datos del receptor');
            $this->fail('Expected an explicit provider rejection.');
        } catch (FelProviderRejectedException) {
            $this->assertSame('cancellation_rejected', $document->refresh()->status);
        }
    }

    public function test_success_http_without_fiscal_confirmation_is_uncertain(): void
    {
        [, $document] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response(['Codigo' => '1'])]);

        $this->assertCancellationIsUncertain($document);
    }

    public function test_timeout_during_cancellation_is_uncertain(): void
    {
        [, $document] = $this->certifiedDocument();
        Http::fake(fn () => throw new ConnectionException('cancellation timeout'));

        $this->assertCancellationIsUncertain($document);
    }

    public function test_server_error_during_cancellation_is_uncertain(): void
    {
        [, $document] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response(['message' => 'upstream unavailable'], 503)]);

        $this->assertCancellationIsUncertain($document);
    }

    public function test_html_or_malformed_json_cancellation_response_is_uncertain(): void
    {
        [, $htmlDocument] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response('<html>upstream unavailable</html>', 200, ['Content-Type' => 'text/html'])]);
        $this->assertCancellationIsUncertain($htmlDocument);

        [, $jsonDocument] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response('{"Codigo":', 200, ['Content-Type' => 'application/json'])]);
        $this->assertCancellationIsUncertain($jsonDocument);
    }

    private function assertCancellationIsUncertain(ElectronicDocument $document): void
    {
        try {
            app(DigifactInvoiceService::class)->cancelElectronicDocument($document, 'Error en datos del receptor');
            $this->fail('Expected an uncertain cancellation exception.');
        } catch (FelException) {
            $this->assertSame('cancellation_unknown', $document->refresh()->status);
            $this->assertNull($document->cancelled_at);
        }
    }

    /** @return array{Business, ElectronicDocument} */
    private function certifiedDocument(): array
    {
        $business = Business::query()->create([
            'name' => 'Cancellation Contract Test',
            'country' => 'GT',
            'currency' => 'GTQ',
            'timezone' => 'America/Guatemala',
            'is_active' => true,
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

        $sale = Sale::query()->create([
            'business_id' => $business->id,
            'business_number' => Sale::query()->count() + 1,
            'customer_name' => 'Consumidor Final',
            'customer_doc_type' => 'CF',
            'customer_doc_number' => 'CF',
            'customer_country' => 'GT',
            'subtotal_before_discount' => 100,
            'total' => 100,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'amount_paid' => 100,
            'status' => 'completed',
            'document_type' => 'invoice',
            'certification_status' => 'certified',
            'fel_uuid' => self::UUID,
            'fel_issued_at' => now('America/Guatemala')->setDate(2026, 10, 1)->setTime(9, 30),
        ]);

        $document = ElectronicDocument::query()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'provider' => 'digifact',
            'environment' => 'test',
            'document_type' => 'invoice',
            'status' => 'certified',
            'uuid' => self::UUID,
            'issued_at' => now('America/Guatemala')->setDate(2026, 10, 1)->setTime(9, 30),
            'certification_date' => now('America/Guatemala'),
        ]);

        $sale->update(['electronic_document_id' => $document->id]);

        return [$business, $document];
    }
}
