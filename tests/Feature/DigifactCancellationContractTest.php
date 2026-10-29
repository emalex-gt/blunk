<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ElectronicDocument;
use App\Models\FelCancellationRequest;
use App\Models\Sale;
use App\Models\TenantFelPhrase;
use App\Models\TenantFelSetting;
use App\Models\User;
use App\Services\Fel\FelCancellationFinalizer;
use App\Services\Fel\FelCancellationRequestService;
use App\Services\Fel\FelException;
use App\Services\Fel\Providers\Digifact\DigifactClient;
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

    public function test_cancellation_request_is_persisted_before_the_provider_is_called_and_confirmed_only_for_the_same_uuid(): void
    {
        [, $document] = $this->certifiedDocument();
        Http::fake(function () use ($document) {
            $this->assertDatabaseHas('fel_cancellation_requests', [
                'electronic_document_id' => $document->id,
                'status' => FelCancellationRequest::PENDING,
            ]);

            return Http::response(['Codigo' => '1', 'Autorizacion' => self::UUID]);
        });

        $request = $this->requestCancellation($document);

        $this->assertSame(FelCancellationRequest::CONFIRMED, $request->status);
        $this->assertNotNull($request->confirmed_at);
        $this->assertSame('certified', $document->refresh()->status);
    }

    public function test_cancellation_with_a_different_uuid_is_uncertain_not_confirmed(): void
    {
        [, $document] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response(['Codigo' => '1', 'Autorizacion' => 'OTHER-UUID'])]);

        $request = $this->requestCancellation($document);

        $this->assertSame(FelCancellationRequest::UNKNOWN, $request->status);
        $this->assertSame('certified', $document->refresh()->status);
    }

    public function test_explicit_cancellation_rejection_is_recorded_as_rejected(): void
    {
        [, $document] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response(['Codigo' => '0', 'Mensaje' => 'Anulación rechazada'])]);

        $request = $this->requestCancellation($document);

        $this->assertSame(FelCancellationRequest::REJECTED, $request->status);
        $this->assertSame('certified', $document->refresh()->status);
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

    public function test_unknown_cancellation_is_not_sent_again_automatically(): void
    {
        [, $document] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response(['message' => 'upstream unavailable'], 503)]);

        $first = $this->requestCancellation($document);
        $second = $this->requestCancellation($document);

        $this->assertSame(FelCancellationRequest::UNKNOWN, $first->status);
        $this->assertSame($first->id, $second->id);
        Http::assertSentCount(1);
    }

    public function test_confirmed_cancellation_is_not_sent_again_and_leaves_commercial_effects_untouched(): void
    {
        [, $document] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response(['Codigo' => '1', 'Autorizacion' => self::UUID])]);

        $first = $this->requestCancellation($document);
        $second = $this->requestCancellation($document);

        $this->assertSame(FelCancellationRequest::CONFIRMED, $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('completed', $document->sale->refresh()->status);
        Http::assertSentCount(1);
    }

    public function test_confirmed_cancellation_can_be_retained_for_manual_resolution_without_another_provider_call(): void
    {
        [, $document] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response(['Codigo' => '1', 'Autorizacion' => self::UUID])]);

        $confirmed = $this->requestCancellation($document);
        $manual = app(FelCancellationRequestService::class)->markManualResolutionRequired(
            $confirmed,
            'La reversión comercial local requiere atención manual.',
        );

        $this->assertSame(FelCancellationRequest::MANUAL_RESOLUTION_REQUIRED, $manual->status);
        $this->assertNotNull($manual->confirmed_at);
        $this->assertSame('completed', $document->sale->refresh()->status);
        Http::assertSentCount(1);
    }

    public function test_confirmed_cancellation_finalizes_local_effects_once_without_another_provider_call(): void
    {
        [$business, $document] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response(['Codigo' => '1', 'Autorizacion' => self::UUID])]);
        $confirmed = $this->requestCancellation($document);
        $actor = User::factory()->create(['business_id' => $business->id]);

        $first = app(FelCancellationFinalizer::class)->finalize($confirmed, $actor, $business->id);
        $second = app(FelCancellationFinalizer::class)->finalize($confirmed->refresh(), $actor, $business->id);

        $this->assertSame('cancelled', $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertNotNull($confirmed->refresh()->finalized_at);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        Http::assertSentCount(1);
    }

    public function test_finalization_failure_preserves_fiscal_confirmation_and_records_the_error(): void
    {
        [$business, $document] = $this->certifiedDocument();
        Http::fake(['*CancelFelGT' => Http::response(['Codigo' => '1', 'Autorizacion' => self::UUID])]);
        $confirmed = $this->requestCancellation($document);
        $confirmed->update(['uuid' => 'MISMATCHED-UUID']);
        $actor = User::factory()->create(['business_id' => $business->id]);

        try {
            app(FelCancellationFinalizer::class)->finalize($confirmed->refresh(), $actor, $business->id);
            $this->fail('Expected local finalization to reject mismatched fiscal identity.');
        } catch (FelException) {
            $this->assertSame(FelCancellationRequest::CONFIRMED, $confirmed->refresh()->status);
            $this->assertNull($confirmed->finalized_at);
            $this->assertNotEmpty($confirmed->finalization_error);
            $this->assertSame('completed', $document->sale->refresh()->status);
            Http::assertSentCount(1);
        }
    }

    private function assertCancellationIsUncertain(ElectronicDocument $document): void
    {
        $request = $this->requestCancellation($document);

        $this->assertSame(FelCancellationRequest::UNKNOWN, $request->status);
        $this->assertNull($request->confirmed_at);
        $this->assertSame('certified', $document->refresh()->status);
    }

    private function requestCancellation(ElectronicDocument $document): FelCancellationRequest
    {
        return app(FelCancellationRequestService::class)->request($document, 'Error en datos del receptor');
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
