<?php

namespace App\Services\Fel\Providers\Digifact;

use App\Models\Business;
use App\Models\ElectronicDocument;
use App\Models\FelCertificationAttempt;
use App\Models\FelIncident;
use App\Models\Sale;
use App\Models\TenantFelSetting;
use App\Services\Fel\FelException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DigifactInvoiceService
{
    public function __construct(
        private readonly DigifactNucJsonBuilder $payloadBuilder,
    ) {
    }

    public function certifySale(Sale $sale, array $requestTimings = []): ElectronicDocument
    {
        $felStarted = microtime(true);
        $sale->loadMissing(['business', 'customer', 'items.product', 'payments', 'electronicDocument']);
        $business = $sale->business ?: Business::query()->find($sale->business_id);
        $settings = $this->settings($business);
        $internalReference = $sale->fel_internal_reference ?: $this->payloadBuilder->internalReference($sale);

        if ($sale->fel_internal_reference !== $internalReference) {
            $sale->forceFill(['fel_internal_reference' => $internalReference])->save();
        }

        $document = $sale->electronicDocument ?: $this->createPendingDocument($sale, $settings);
        $payload = $this->payloadBuilder->buildInvoicePayload($sale, $settings);
        $felMetadata = $this->payloadBuilder->felMetadataFromPayload($payload, $settings);
        $this->validateInvoicePayload($payload, $settings);
        $issuedAtRaw = (string) ($payload['Header']['IssuedDateTime'] ?? now('America/Guatemala')->format('Y-m-d\TH:i:sP'));
        $issuedAt = $this->parseIssuedAt($issuedAtRaw);

        $sale->forceFill([
            'fel_internal_reference' => $internalReference,
            'fel_issued_at' => $issuedAt,
        ])->save();

        $documentUpdate = [
            'status' => 'pending',
            'internal_reference' => $internalReference,
            'issued_at' => $issuedAt,
            'request_payload' => $payload,
            'error_message' => null,
        ];

        if (Schema::hasColumn('electronic_documents', 'metadata')) {
            $documentUpdate['metadata'] = $felMetadata;
        }

        $document->update($documentUpdate);

        if (FelCertificationAttempt::query()
            ->where('sale_id', $sale->id)
            ->whereNotNull('request_payload')
            ->whereIn('status', ['pending', 'certified'])
            ->exists()
        ) {
            Log::warning('Duplicate FEL certification request candidate detected', [
                'business_id' => $sale->business_id,
                'sale_id' => $sale->id,
                'internal_reference' => $internalReference,
            ]);
        }

        $attempt = $this->createAttempt($sale, $document, $settings, $internalReference, $issuedAt, $payload);

        Log::info('Digifact certification start', [
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'electronic_document_id' => $document->id,
            'environment' => $settings->environment,
            'document_type' => $sale->document_type,
            'format_requested' => 'XML',
            'internal_reference' => $internalReference,
        ]);

        $client = null;

        try {
            if (config('app.debug') && app()->environment('local')) {
                $this->writeDebugPayload($payload);

                Log::debug('Digifact certification debug payload', [
                    'seller_json' => json_encode($payload['Seller'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    'buyer_json' => json_encode($payload['Buyer'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    'api_taxid_padded' => DigifactNit::padIssuerNitForApi($settings->issuer_tax_id),
                    'payload_seller_taxid' => $payload['Seller']['TaxID'] ?? null,
                ]);
            }

            $client = DigifactClient::forBusiness($business, $settings);
            $response = $client->certify($payload, 'XML');
            $normalized = $this->extractCertificationIdentifiers($response);
            $sanitizedResponse = $this->sanitizeProviderResponse($response);

            if (filled($normalized['xml_base64'])) {
                $xml = base64_decode((string) $normalized['xml_base64'], true);
                $xmlData = $xml ? app(\App\Services\Fel\FelCertifiedXmlParser::class)->parse($xml) : [];
                $normalized['uuid'] = $xmlData['uuid'] ?? $normalized['uuid'];
                $normalized['series'] = $xmlData['series'] ?? $normalized['series'];
                $normalized['number'] = $xmlData['number'] ?? $normalized['number'];

                if (! filled($normalized['certification_date']) && filled($xmlData['certification_date'] ?? null)) {
                    $normalized['certification_date'] = \Illuminate\Support\Carbon::parse($xmlData['certification_date']);
                }
            }

            Log::info('Digifact certification response parsed', [
                'business_id' => $business->id,
                'sale_id' => $sale->id,
                'electronic_document_id' => $document->id,
                'response_keys' => array_keys($response),
                'detected_uuid' => $normalized['uuid'],
                'detected_series' => $normalized['series'],
                'detected_number' => $normalized['number'],
            ]);

            if (! filled($normalized['uuid'])) {
                throw new FelException(
                    'No se pudo identificar la autorización FEL devuelta por Digifact.',
                    responsePayload: $response,
                );
            }

            $certifiedDocumentUpdate = [
                'status' => 'certified',
                'internal_reference' => $internalReference,
                'issued_at' => $issuedAt,
                'uuid' => $normalized['uuid'],
                'series' => $normalized['series'],
                'number' => $normalized['number'],
                'certification_date' => $normalized['certification_date'],
                'response_payload' => $sanitizedResponse,
                'xml_base64' => null,
                'pdf_base64' => null,
                'html' => null,
                'error_message' => null,
            ];

            if (Schema::hasColumn('electronic_documents', 'metadata')) {
                $certifiedDocumentUpdate['metadata'] = $felMetadata;
            }

            $document->update($certifiedDocumentUpdate);

            $document = $document->refresh();

            $sale->update([
                'electronic_document_id' => $document->id,
                'certification_status' => 'certified',
                'fel_internal_reference' => $internalReference,
                'fel_uuid' => $normalized['uuid'],
                'fel_series' => $normalized['series'],
                'fel_number' => $normalized['number'],
                'fel_xml_path' => null,
                'fel_html_path' => null,
                'fel_pdf_url' => null,
                'fel_pdf_path' => null,
                'fel_certified_at' => $normalized['certification_date'],
                'fel_issued_at' => $issuedAt,
                'fel_status' => 'CERTIFIED',
                'fel_raw_response' => $sanitizedResponse,
            ]);

            $timings = $this->certificationTimings($requestTimings, $client, $felStarted);

            $attempt->update([
                'status' => 'certified',
                'response_payload' => $sanitizedResponse,
                'error_message' => null,
                'finished_at' => now(),
                'timings' => $timings,
            ]);

            Log::info('Digifact certification success', [
                'business_id' => $business->id,
                'sale_id' => $sale->id,
                'electronic_document_id' => $document->id,
                'has_uuid' => filled($normalized['uuid']),
                'environment' => $settings->environment,
                'document_type' => $sale->document_type,
                'format_requested' => 'XML',
                ...$timings,
            ]);

            if (($timings['token_refresh_count'] ?? 0) > 1) {
                Log::warning('Multiple Digifact token refreshes during certification', [
                    'business_id' => $business->id,
                    'sale_id' => $sale->id,
                    'token_refresh_count' => $timings['token_refresh_count'],
                ]);
            }

            $this->logDebugTimings($sale, $timings);

            return $document->refresh();
        } catch (ConnectionException $exception) {
            $message = 'No se pudo confirmar la certificacion. Puedes reintentar.';
            $timings = $this->certificationTimings($requestTimings, $client, $felStarted);

            $attempt->update([
                'status' => 'unknown',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'timings' => $timings,
            ]);

            $document->update([
                'status' => 'unknown',
                'error_message' => $message,
            ]);

            $sale->update([
                'electronic_document_id' => $document->id,
                'certification_status' => 'unknown',
                'fel_status' => 'UNKNOWN',
            ]);

            $this->createIncident($sale, $internalReference, 'No se pudo confirmar la certificacion FEL con Digifact.', [
                'attempt_id' => $attempt->id,
                'error' => $exception->getMessage(),
            ]);

            Log::warning('Digifact certification unknown', [
                'business_id' => $business->id,
                'sale_id' => $sale->id,
                'electronic_document_id' => $document->id,
                'error' => $exception->getMessage(),
                'environment' => $settings->environment,
                'document_type' => $sale->document_type,
                'format_requested' => 'XML',
                ...$timings,
            ]);
            $this->logDebugTimings($sale, $timings);

            throw new FelException($message, previous: $exception);
        } catch (\Throwable $exception) {
            $message = $exception instanceof FelException
                ? $exception->getMessage()
                : 'No se pudo certificar la factura.';

            $failurePayload = $exception instanceof FelException ? $exception->responsePayload() : null;
            $updates = [
                'status' => 'failed',
                'error_message' => $message,
            ];

            if ($failurePayload !== null) {
                $updates['response_payload'] = $this->sanitizeProviderResponse($failurePayload);
            }

            $document->update($updates);
            $timings = $this->certificationTimings($requestTimings, $client, $felStarted);

            $attempt->update([
                'status' => 'failed',
                'response_payload' => $failurePayload !== null ? $this->sanitizeProviderResponse($failurePayload) : null,
                'error_message' => $message,
                'finished_at' => now(),
                'timings' => $timings,
            ]);

            $sale->update([
                'electronic_document_id' => $document->id,
                'certification_status' => 'failed',
            ]);

            Log::error('Digifact certification failed', [
                'business_id' => $business->id,
                'sale_id' => $sale->id,
                'electronic_document_id' => $document->id,
                'error' => $message,
                'environment' => $settings->environment,
                'document_type' => $sale->document_type,
                'format_requested' => 'XML',
                ...$timings,
            ]);
            $this->logDebugTimings($sale, $timings);

            throw new FelException($message, previous: $exception);
        }
    }

    public function retryCertification(Sale $sale): ElectronicDocument
    {
        $sale->loadMissing(['business', 'customer', 'items.product', 'payments', 'electronicDocument']);
        $business = $sale->business ?: Business::query()->find($sale->business_id);
        $settings = $this->settings($business);
        $document = $sale->electronicDocument ?: $this->createPendingDocument($sale, $settings);
        $internalReference = $sale->fel_internal_reference
            ?: $document->internal_reference
            ?: $this->payloadBuilder->internalReference($sale);
        $issuedAt = $sale->fel_issued_at ?: $document->issued_at ?: $sale->created_at;

        $attempt = FelCertificationAttempt::query()->create([
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'electronic_document_id' => $document->id,
            'provider' => 'digifact',
            'environment' => $settings->environment,
            'internal_reference' => $internalReference,
            'issued_at' => $issuedAt,
            'status' => 'pending',
            'started_at' => now(),
            'created_by' => auth()->id() ?: $sale->created_by,
        ]);

        $this->createIncident($sale, $internalReference, 'Se inició un reintento de certificación FEL.', [
            'attempt_id' => $attempt->id,
            'previous_status' => $sale->certification_status ?: $document->status,
        ]);

        try {
            $response = DigifactClient::forBusiness($business)
                ->findDocumentByInternalReference($settings, $internalReference, $issuedAt ?: now('America/Guatemala'));

            if ($response !== []) {
                $sanitized = $this->sanitizeProviderResponse($response);
                $document = $this->applyCertifiedResponse(
                    $sale,
                    $document,
                    $settings,
                    $internalReference,
                    $issuedAt,
                    $response,
                    $sanitized,
                    'reconciled',
                );

                $attempt->update([
                    'status' => 'reconciled',
                    'response_payload' => $sanitized,
                    'finished_at' => now(),
                ]);

                return $document;
            }
        } catch (ConnectionException $exception) {
            $attempt->update([
                'status' => 'unknown',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            $this->createIncident($sale, $internalReference, 'No se pudo conciliar la certificacion FEL antes del reintento.', [
                'attempt_id' => $attempt->id,
                'error' => $exception->getMessage(),
            ]);

            throw new FelException('No se pudo confirmar la certificacion. Puedes reintentar.', previous: $exception);
        } catch (FelException $exception) {
            $attempt->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }

        return $this->certifySale($sale->refresh());
    }

    public function applyReconciledResponse(Sale $sale, array $response, Carbon|string|null $issuedAt = null): ElectronicDocument
    {
        $sale->loadMissing(['business', 'customer', 'items.product', 'payments', 'electronicDocument']);
        $business = $sale->business ?: Business::query()->find($sale->business_id);
        $settings = $this->settings($business);
        $document = $sale->electronicDocument ?: $this->createPendingDocument($sale, $settings);
        $internalReference = $sale->fel_internal_reference
            ?: $document->internal_reference
            ?: $this->payloadBuilder->internalReference($sale);
        $issuedAt ??= $sale->fel_issued_at ?: $document->issued_at ?: $sale->created_at;

        return $this->applyCertifiedResponse(
            $sale,
            $document,
            $settings,
            $internalReference,
            $issuedAt,
            $response,
            $this->sanitizeProviderResponse($response),
            'reconciled_manual',
        );
    }

    public function cancelElectronicDocument(ElectronicDocument $document, string $reason): ElectronicDocument
    {
        $document->loadMissing(['business', 'sale']);
        $business = $document->business;
        $settings = $this->settings($business);
        $payload = $this->payloadBuilder->buildCancellationPayload($document, $reason);

        $document->update([
            'status' => 'cancellation_pending',
            'cancellation_request_payload' => $payload,
            'error_message' => null,
        ]);

        Log::info('Digifact cancellation start', [
            'business_id' => $business->id,
            'sale_id' => $document->sale_id,
            'electronic_document_id' => $document->id,
            'environment' => $settings->environment,
        ]);

        try {
            $response = DigifactClient::forBusiness($business)->cancel($payload);

            $document->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_response_payload' => $response,
                'error_message' => null,
            ]);

            Log::info('Digifact cancellation success', [
                'business_id' => $business->id,
                'sale_id' => $document->sale_id,
                'electronic_document_id' => $document->id,
            ]);

            return $document->refresh();
        } catch (\Throwable $exception) {
            $message = $exception instanceof FelException
                ? $exception->getMessage()
                : 'No se pudo anular la factura electronica.';

            $document->update([
                'status' => 'cancellation_failed',
                'cancellation_response_payload' => $exception instanceof FelException ? $exception->responsePayload() : null,
                'error_message' => $message,
            ]);

            Log::error('Digifact cancellation failed', [
                'business_id' => $business->id,
                'sale_id' => $document->sale_id,
                'electronic_document_id' => $document->id,
                'error' => $message,
            ]);

            throw new FelException($message, previous: $exception);
        }
    }

    private function createPendingDocument(Sale $sale, TenantFelSetting $settings): ElectronicDocument
    {
        return ElectronicDocument::query()->create([
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'provider' => 'digifact',
            'environment' => $settings->environment,
            'document_type' => 'invoice',
            'status' => 'pending',
            'created_by' => $sale->created_by,
        ]);
    }

    private function createAttempt(
        Sale $sale,
        ElectronicDocument $document,
        TenantFelSetting $settings,
        string $internalReference,
        ?Carbon $issuedAt,
        array $payload,
    ): FelCertificationAttempt {
        return FelCertificationAttempt::query()->create([
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'electronic_document_id' => $document->id,
            'provider' => 'digifact',
            'environment' => $settings->environment,
            'internal_reference' => $internalReference,
            'issued_at' => $issuedAt,
            'status' => 'pending',
            'request_payload' => $payload,
            'started_at' => now(),
            'created_by' => auth()->id() ?: $sale->created_by,
        ]);
    }

    private function parseIssuedAt(string $issuedAt): ?Carbon
    {
        try {
            return Carbon::parse($issuedAt);
        } catch (\Throwable) {
            return null;
        }
    }

    private function applyCertifiedResponse(
        Sale $sale,
        ElectronicDocument $document,
        TenantFelSetting $settings,
        string $internalReference,
        Carbon|string|null $issuedAt,
        array $response,
        array $sanitizedResponse,
        string $source,
    ): ElectronicDocument {
        $normalized = $this->extractCertificationIdentifiers($response);

        if (filled($normalized['xml_base64'])) {
            $xml = base64_decode((string) $normalized['xml_base64'], true);
            $xmlData = $xml ? app(\App\Services\Fel\FelCertifiedXmlParser::class)->parse($xml) : [];
            $normalized['uuid'] = $xmlData['uuid'] ?? $normalized['uuid'];
            $normalized['series'] = $xmlData['series'] ?? $normalized['series'];
            $normalized['number'] = $xmlData['number'] ?? $normalized['number'];

            if (! filled($normalized['certification_date']) && filled($xmlData['certification_date'] ?? null)) {
                $normalized['certification_date'] = Carbon::parse($xmlData['certification_date']);
            }
        }

        if (! filled($normalized['uuid'])) {
            throw new FelException(
                'No se pudo identificar la autorización FEL devuelta por Digifact.',
                responsePayload: $response,
            );
        }

        $issuedAtValue = $issuedAt instanceof Carbon
            ? $issuedAt
            : ($issuedAt ? $this->parseIssuedAt((string) $issuedAt) : $normalized['issued_at']);

        $document->update([
            'status' => 'certified',
            'internal_reference' => $internalReference,
            'issued_at' => $issuedAtValue,
            'uuid' => $normalized['uuid'],
            'series' => $normalized['series'],
            'number' => $normalized['number'],
            'certification_date' => $normalized['certification_date'],
            'response_payload' => $sanitizedResponse,
            'xml_base64' => null,
            'pdf_base64' => null,
            'html' => null,
            'error_message' => null,
        ]);

        $sale->update([
            'electronic_document_id' => $document->id,
            'certification_status' => 'certified',
            'fel_internal_reference' => $internalReference,
            'fel_uuid' => $normalized['uuid'],
            'fel_series' => $normalized['series'],
            'fel_number' => $normalized['number'],
            'fel_xml_path' => null,
            'fel_html_path' => null,
            'fel_pdf_url' => null,
            'fel_pdf_path' => null,
            'fel_certified_at' => $normalized['certification_date'],
            'fel_issued_at' => $issuedAtValue,
            'fel_status' => 'CERTIFIED',
            'fel_raw_response' => $sanitizedResponse,
        ]);

        Log::info('Digifact certification applied', [
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'electronic_document_id' => $document->id,
            'source' => $source,
            'uuid' => $normalized['uuid'],
            'series' => $normalized['series'],
            'number' => $normalized['number'],
        ]);

        return $document->refresh();
    }

    private function createIncident(Sale $sale, string $internalReference, string $message, array $metadata = []): void
    {
        FelIncident::query()->create([
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'internal_reference' => $internalReference,
            'type' => 'possible_duplicate',
            'severity' => 'warning',
            'status' => 'open',
            'message' => $message,
            'metadata' => $metadata,
            'created_by' => auth()->id(),
        ]);
    }

    private function validateInvoicePayload(array $payload, ?TenantFelSetting $settings = null): void
    {
        foreach (['Version', 'CountryCode', 'Header', 'Seller', 'Buyer', 'Items', 'Totals', 'AdditionalDocumentInfo'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new FelException("Payload FEL invalido: falta {$key}.");
            }
        }

        foreach (['DocType', 'IssuedDateTime', 'Currency'] as $key) {
            if (! array_key_exists($key, $payload['Header'] ?? [])) {
                throw new FelException("Payload FEL invalido: falta Header.{$key}.");
            }
        }

        foreach (['TaxID', 'TaxIDAdditionalInfo', 'AdditionlInfo', 'Name', 'BranchInfo'] as $key) {
            if (! array_key_exists($key, $payload['Seller'] ?? [])) {
                throw new FelException("Payload FEL invalido: falta Seller.{$key}.");
            }
        }

        $sellerTaxIdAdditionalInfo = $payload['Seller']['TaxIDAdditionalInfo'] ?? null;

        if (! is_array($sellerTaxIdAdditionalInfo) || ! array_is_list($sellerTaxIdAdditionalInfo)) {
            throw new FelException('Seller.TaxIDAdditionalInfo must be an array.');
        }

        $firstSellerTaxIdAdditionalInfo = $sellerTaxIdAdditionalInfo[0] ?? null;

        if (! is_array($firstSellerTaxIdAdditionalInfo)) {
            throw new FelException('Payload FEL invalido: falta Seller.TaxIDAdditionalInfo[0].');
        }

        foreach (['Name', 'Value'] as $key) {
            if (! array_key_exists($key, $firstSellerTaxIdAdditionalInfo)) {
                throw new FelException("Payload FEL invalido: falta Seller.TaxIDAdditionalInfo[0].{$key}.");
            }
        }

        if (($firstSellerTaxIdAdditionalInfo['Name'] ?? null) !== 'AfiliacionIVA') {
            throw new FelException('Payload FEL invalido: Seller.TaxIDAdditionalInfo[0].Name debe ser AfiliacionIVA.');
        }

        $payloadSellerTaxId = (string) ($payload['Seller']['TaxID'] ?? '');
        $cleanIssuerNit = DigifactNit::cleanIssuerNitForPayload($settings?->issuer_tax_id);

        if (str_starts_with($payloadSellerTaxId, '0') && $payloadSellerTaxId !== $cleanIssuerNit) {
            throw new FelException('El NIT emisor del payload FEL no debe ir completado con ceros.');
        }

        $this->validateSellerPhrases($payload['Seller']['AdditionlInfo'] ?? null);

        foreach (['Code', 'Name', 'AddressInfo'] as $key) {
            if (! array_key_exists($key, $payload['Seller']['BranchInfo'] ?? [])) {
                throw new FelException("Payload FEL invalido: falta Seller.BranchInfo.{$key}.");
            }
        }

        foreach (['Address', 'City', 'District', 'State', 'Country'] as $key) {
            if (! array_key_exists($key, $payload['Seller']['BranchInfo']['AddressInfo'] ?? [])) {
                throw new FelException('FEL no configurada: faltan datos del establecimiento.');
            }
        }

        foreach (['TaxID', 'Name', 'AddressInfo'] as $key) {
            if (! array_key_exists($key, $payload['Buyer'] ?? [])) {
                throw new FelException("Payload FEL invalido: falta Buyer.{$key}.");
            }
        }

        foreach (['Address', 'City', 'District', 'State', 'Country'] as $key) {
            if (! array_key_exists($key, $payload['Buyer']['AddressInfo'] ?? [])) {
                throw new FelException("Payload FEL invalido: falta Buyer.AddressInfo.{$key}.");
            }
        }

        if (! is_array($payload['Items']) || $payload['Items'] === []) {
            throw new FelException('Payload FEL invalido: Items no puede estar vacio.');
        }

        foreach ($payload['Items'] as $index => $item) {
            foreach (['NumberLine', 'Type', 'Description', 'Qty', 'Price', 'Taxes', 'Totals'] as $key) {
                if (! array_key_exists($key, $item)) {
                    throw new FelException("Payload FEL invalido: falta Items[{$index}].{$key}.");
                }
            }

            if (! array_key_exists('TotalItem', $item['Totals'] ?? [])) {
                throw new FelException("Payload FEL invalido: falta Items[{$index}].Totals.TotalItem.");
            }
        }

        if (! array_key_exists('GrandTotal', $payload['Totals'] ?? [])) {
            throw new FelException('Payload FEL invalido: falta Totals.GrandTotal.');
        }

        if (! is_array($payload['Totals']['GrandTotal']) || ! array_key_exists('InvoiceTotal', $payload['Totals']['GrandTotal'])) {
            throw new FelException('Payload FEL invalido: falta Totals.GrandTotal.InvoiceTotal.');
        }
    }

    private function validateSellerPhrases(mixed $additionlInfo): void
    {
        if (! is_array($additionlInfo) || ! array_is_list($additionlInfo)) {
            throw new FelException('Payload FEL invalido: Seller.AdditionlInfo debe ser un arreglo.');
        }

        $typeOneDataIdentifiers = [];
        $scenarioOneDataIdentifiers = [];

        foreach ($additionlInfo as $index => $info) {
            if (! is_array($info)) {
                throw new FelException("Payload FEL invalido: Seller.AdditionlInfo[{$index}] no es valido.");
            }

            foreach (['Name', 'Data', 'Value'] as $key) {
                if (! array_key_exists($key, $info)) {
                    throw new FelException("Payload FEL invalido: falta Seller.AdditionlInfo[{$index}].{$key}.");
                }
            }

            if ($info['Name'] === 'TipoFrase' && (string) $info['Value'] === '1') {
                $typeOneDataIdentifiers[] = (string) $info['Data'];
            }

            if ($info['Name'] === 'Escenario' && (string) $info['Value'] === '2') {
                $scenarioOneDataIdentifiers[] = (string) $info['Data'];
            }
        }

        foreach ($typeOneDataIdentifiers as $dataIdentifier) {
            if (in_array($dataIdentifier, $scenarioOneDataIdentifiers, true)) {
                return;
            }
        }

        throw new FelException('Payload FEL invalido: FACT debe incluir TipoFrase 1 y Escenario 2 con el mismo Data.');
    }

    private function writeDebugPayload(array $payload): void
    {
        $directory = storage_path('app/debug');

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put(
            $directory.'/digifact-last-payload.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    private function settings(Business $business): TenantFelSetting
    {
        if ($business->country !== 'GT') {
            throw new FelException('La facturacion electronica FEL esta disponible solo para Guatemala.');
        }

        $settings = TenantFelSetting::query()->firstOrCreate(
            ['business_id' => $business->id],
            [
                'provider' => 'digifact',
                'environment' => 'test',
                'enabled' => false,
                'test_base_url' => config('digifact.test_base_url'),
                'production_base_url' => config('digifact.production_base_url'),
            ],
        );

        if (! $settings->isConfigured()) {
            throw new FelException($settings->configurationErrorMessage());
        }

        return $settings;
    }

    public function extractCertificationIdentifiers(array $response): array
    {
        $flat = $this->flattenResponse($response);

        return [
            'uuid' => $this->firstValue($flat, [
                'uuid',
                'UUID',
                'Uuid',
                'response.uuid',
                'RESPONSE.UUID',
                'RESPONSE.uuid',
                'RESPONSE.0.UUID',
                'RESPONSE.0.uuid',
                'autorizacion',
                'Autorizacion',
                'AUTORIZACION',
                'Authorization',
                'authorization',
                'RESPONSE.AUTORIZACION',
                'RESPONSE.autorizacion',
                'RESPONSE.Autorizacion',
                'RESPONSE.Authorization',
                'RESPONSE.0.AUTORIZACION',
                'RESPONSE.0.autorizacion',
                'RESPONSE.0.Autorizacion',
                'RESPONSE.0.Authorization',
                'authnumber',
                'AUTHNUMBER',
                'AuthNumber',
                'NumeroAutorizacion',
                'Numero_Autorizacion',
                'Numero_de_Autorizacion',
                'NumeroDocumento',
                'Numero_Documento',
                'RESPONSE.NumeroAutorizacion',
                'RESPONSE.0.NumeroAutorizacion',
                'RESPONSE.NumeroDocumento',
                'RESPONSE.0.NumeroDocumento',
                'NumeroAutorizacionSAT',
                'AutorizacionSAT',
                'AUTH_NUMBER',
                'AUTHORIZATION',
                'AuthorizationNumber',
                'authorization_number',
                'CAE',
                'DTE.NumeroAutorizacion',
            ]),
            'series' => $this->firstValue($flat, [
                'batch',
                'Batch',
                'BATCH',
                'serie',
                'series',
                'Serie',
                'SERIE',
                'SerieDocumento',
                'serieDocumento',
                'RESPONSE.Serie',
                'RESPONSE.0.Serie',
            ]),
            'number' => $this->firstValue($flat, [
                'serial',
                'Serial',
                'SERIAL',
                'numero',
                'number',
                'Numero',
                'NUMERO',
                'NumeroDocumento',
                'numeroDocumento',
                'Numero_Documento',
                'RESPONSE.Numero',
                'RESPONSE.0.Numero',
                'RESPONSE.NumeroDocumento',
                'RESPONSE.0.NumeroDocumento',
            ]),
            'issued_at' => ($issuedAt = $this->firstValue($flat, [
                'issuedTimeStamp',
                'issuedtimestamp',
                'issued_at',
            ]))
                ? \Illuminate\Support\Carbon::parse($issuedAt)
                : null,
            'certification_date' => ($date = $this->firstValue($flat, [
                'enrolledTimeStamp',
                'enrolledtimestamp',
                'issuedTimeStamp',
                'issuedtimestamp',
                'fecha_certificacion',
                'FechaCertificacion',
                'Fecha_de_certificacion',
                'FechaCertificacionSat',
                'certification_date',
                'Fecha_DTE',
                'FechaHoraCertificacion',
                'RESPONSE.FechaCertificacion',
                'RESPONSE.0.FechaCertificacion',
            ]))
                ? \Illuminate\Support\Carbon::parse($date)
                : now(),
            'xml_base64' => $this->firstValue($flat, ['responseData1', 'responsedata1', 'ResponseData1', 'RESPONSE.responseData1', 'RESPONSE.0.ResponseData1', 'xml', 'XML', 'Xml', 'xml_base64', 'XMLBase64', 'XmlBase64', 'RESPONSE.XML', 'RESPONSE.0.XML']),
            'html_base64' => $this->firstValue($flat, ['responseData2', 'responsedata2', 'ResponseData2', 'RESPONSE.responseData2', 'RESPONSE.0.ResponseData2', 'html_base64', 'HTMLBase64', 'HtmlBase64']),
            'pdf_base64' => $this->firstValue($flat, ['responseData3', 'responsedata3', 'ResponseData3', 'RESPONSE.responseData3', 'RESPONSE.0.ResponseData3', 'pdf', 'PDF', 'Pdf', 'pdf_base64', 'PDFBase64', 'PdfBase64', 'RESPONSE.PDF', 'RESPONSE.0.PDF']),
            'pdf_url' => $this->firstValue($flat, ['url', 'URL', 'Url', 'pdf_url', 'pdfUrl', 'PDFUrl', 'document_url', 'documentUrl', 'download_url', 'downloadUrl', 'RESPONSE.url', 'RESPONSE.0.url']),
            'html' => $this->firstValue($flat, ['html', 'HTML', 'Html', 'HtmlDte', 'HTMLDTE', 'RESPONSE.HTML', 'RESPONSE.0.HTML']),
        ];
    }

    public function getDocumentContent(Sale $sale, string $format, bool $automaticPrint = false): array
    {
        $started = microtime(true);
        $sale->loadMissing(['business', 'electronicDocument']);
        $business = $sale->business ?: Business::query()->findOrFail($sale->business_id);
        $document = $sale->electronicDocument;
        $format = strtoupper($format);

        if (! $document || ! filled($document->uuid)) {
            throw new FelException('La factura FEL no tiene autorizacion para consultar el documento.');
        }

        $settings = $this->settings($business);
        $client = DigifactClient::forBusiness($business, $settings);
        try {
            $response = $client->getDocument($document, $format);
        } catch (\Throwable $exception) {
            $this->recordDocumentTimings(
                $sale,
                $format,
                $client->timingSummary(),
                0.0,
                round((microtime(true) - $started) * 1000, 2),
                $automaticPrint,
            );

            throw $exception;
        }

        $decodeMs = 0.0;
        $content = $this->extractDocumentContent($response, $format, $decodeMs);

        if (! $content) {
            $this->recordDocumentTimings(
                $sale,
                $format,
                $client->timingSummary(),
                $decodeMs,
                round((microtime(true) - $started) * 1000, 2),
                $automaticPrint,
            );

            throw new FelException('No se encontro el documento imprimible en Digifact.');
        }

        $this->recordDocumentTimings(
            $sale,
            $format,
            $client->timingSummary(),
            $decodeMs,
            round((microtime(true) - $started) * 1000, 2),
            $automaticPrint,
        );

        return $content;
    }

    private function extractDocumentContent(array|string $response, string $format, ?float &$decodeMs = null): ?array
    {
        $decodeStarted = microtime(true);

        if (is_string($response)) {
            $detected = $this->detectEncodedOrRawDocument($response);
            $decodeMs = round((microtime(true) - $decodeStarted) * 1000, 2);

            if ($detected && strtoupper($detected['format']) === $format) {
                return [
                    'content' => $detected['content'],
                    'content_type' => $this->contentTypeForFormat($detected['format']),
                    'extension' => $detected['format'],
                ];
            }

            return null;
        }

        $flat = $this->flattenResponse($response);

        $base64 = match ($format) {
            'PDF' => $this->firstValue($flat, ['body_base64', 'responseData3', 'ResponseData3', 'RESPONSE.0.ResponseData3', 'pdf', 'PDF', 'pdf_base64', 'PDFBase64']),
            'HTML' => $this->firstValue($flat, ['responseData2', 'ResponseData2', 'RESPONSE.0.ResponseData2', 'html_base64', 'HTMLBase64']),
            'XML' => $this->firstValue($flat, ['responseData1', 'ResponseData1', 'RESPONSE.0.ResponseData1', 'xml_base64', 'XMLBase64']),
            default => null,
        };

        if (! filled($base64)) {
            $decodeMs = round((microtime(true) - $decodeStarted) * 1000, 2);
            return null;
        }

        $content = base64_decode((string) $base64, true);
        $decodeMs = round((microtime(true) - $decodeStarted) * 1000, 2);

        if ($content === false || ! $this->contentMatchesFormat($content, strtolower($format))) {
            return null;
        }

        return [
            'content' => $content,
            'content_type' => $this->contentTypeForFormat(strtolower($format)),
            'extension' => strtolower($format),
        ];
    }

    public function recordSaleRequestTiming(Sale $sale, float $totalSaleRequestMs): void
    {
        $attempt = $sale->felCertificationAttempts()->latest('id')->first();

        if (! $attempt) {
            return;
        }

        $timings = array_merge($attempt->timings ?? [], [
            'total_sale_request_ms' => round($totalSaleRequestMs, 2),
        ]);

        $attempt->update(['timings' => $timings]);

        Log::info('FEL sale request timing', [
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'environment' => $attempt->environment,
            'document_type' => $sale->document_type,
            ...$timings,
        ]);

        $this->logDebugTimings($sale, $timings);
    }

    private function certificationTimings(array $requestTimings, ?DigifactClient $client, float $felStarted): array
    {
        $clientTimings = $client?->timingSummary() ?? [];
        $flowMs = round((microtime(true) - $felStarted) * 1000, 2);

        return array_filter([
            'sale_transaction_ms' => isset($requestTimings['sale_transaction_ms'])
                ? round((float) $requestTimings['sale_transaction_ms'], 2)
                : null,
            'token_ms' => round((float) ($clientTimings['token_ms'] ?? 0), 2),
            'token_source' => $clientTimings['token_source'] ?? 'unknown',
            'token_refresh_count' => (int) ($clientTimings['token_refresh_count'] ?? 0),
            'certification_ms' => isset($clientTimings['certification_ms'])
                ? round((float) $clientTimings['certification_ms'], 2)
                : null,
            'certification_http_status' => $clientTimings['certification_http_status'] ?? null,
            'certification_flow_ms' => $flowMs,
            'total_fel_ms' => $flowMs,
        ], fn ($value) => $value !== null);
    }

    private function recordDocumentTimings(
        Sale $sale,
        string $format,
        array $clientTimings,
        float $decodeMs,
        float $documentFlowMs,
        bool $automaticPrint,
    ): void {
        $key = 'get_document_'.mb_strtolower($format).'_ms';
        $measurement = [
            $key => round((float) ($clientTimings[$key] ?? $documentFlowMs), 2),
            str_replace('_ms', '_http_status', $key) => $clientTimings[str_replace('_ms', '_http_status', $key)] ?? null,
            'token_source_document' => $clientTimings['token_source'] ?? 'unknown',
            'token_ms_document' => round((float) ($clientTimings['token_ms'] ?? 0), 2),
        ];
        $measurement = array_filter($measurement, fn ($value) => $value !== null);

        if ($format === 'HTML') {
            $measurement['html_decode_ms'] = round($decodeMs, 2);
        }

        $attempt = $sale->felCertificationAttempts()->latest('id')->first();
        $combinedTimings = $measurement;

        if ($attempt && $automaticPrint) {
            $timings = $attempt->timings ?? [];
            $automaticCalls = (int) ($timings['automatic_get_document_html_calls'] ?? 0) + 1;
            $timings = array_merge($timings, $measurement, [
                'automatic_get_document_html_calls' => $automaticCalls,
                'total_fel_ms' => round((float) ($timings['certification_flow_ms'] ?? 0) + $documentFlowMs, 2),
            ]);
            $attempt->update(['timings' => $timings]);
            $combinedTimings = $timings;

            if ($format === 'HTML' && $automaticCalls > 1) {
                Log::warning('Duplicate automatic FEL HTML document request detected', [
                    'business_id' => $sale->business_id,
                    'sale_id' => $sale->id,
                    'automatic_get_document_html_calls' => $automaticCalls,
                ]);
            }

            $this->logDebugTimings($sale, $timings);
        }

        Log::info('FEL document timing', [
            'business_id' => $sale->business_id,
            'sale_id' => $sale->id,
            'document_type' => $sale->document_type,
            'format_requested' => $format,
            'automatic_print' => $automaticPrint,
            ...$combinedTimings,
        ]);
    }

    private function logDebugTimings(Sale $sale, array $timings): void
    {
        if (! config('app.debug')) {
            return;
        }

        Log::debug("FEL timing sale #{$sale->id}", [
            'token_source' => $timings['token_source'] ?? null,
            'token_ms' => $timings['token_ms'] ?? null,
            'sale_transaction_ms' => $timings['sale_transaction_ms'] ?? null,
            'certification_ms' => $timings['certification_ms'] ?? null,
            'get_document_html_ms' => $timings['get_document_html_ms'] ?? null,
            'html_decode_ms' => $timings['html_decode_ms'] ?? null,
            'total_fel_ms' => $timings['total_fel_ms'] ?? null,
            'total_sale_request_ms' => $timings['total_sale_request_ms'] ?? null,
        ]);
    }

    private function sanitizeProviderResponse(array $response): array
    {
        $sanitized = $this->removeResponseDataPayloads($response);

        $sanitized['has_xml'] = $this->hasResponseData($response, ['responsedata1']);
        $sanitized['has_html'] = $this->hasResponseData($response, ['responsedata2']);
        $sanitized['has_pdf'] = $this->hasResponseData($response, ['responsedata3', 'pdf']);

        return $sanitized;
    }

    private function hasResponseData(array $payload, array $expectedKeys): bool
    {
        foreach ($payload as $key => $value) {
            if (in_array($this->normalizeResponseKey((string) $key), $expectedKeys, true) && filled($value)) {
                return true;
            }

            if (is_array($value) && $this->hasResponseData($value, $expectedKeys)) {
                return true;
            }
        }

        return false;
    }

    private function removeResponseDataPayloads(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if (in_array($this->normalizeResponseKey((string) $key), ['responsedata1', 'responsedata2', 'responsedata3', 'responsedata4'], true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->removeResponseDataPayloads($value) : $value;
        }

        return $clean;
    }

    private function detectEncodedOrRawDocument(string $value): ?array
    {
        $content = base64_decode($value, true);

        if ($content === false) {
            $content = $value;
        }

        foreach (['pdf', 'html', 'xml'] as $format) {
            if ($this->contentMatchesFormat($content, $format)) {
                return [
                    'format' => $format,
                    'key' => "{$format}_path",
                    'content' => $content,
                ];
            }
        }

        return null;
    }

    private function contentMatchesFormat(string $content, string $format): bool
    {
        $trimmed = ltrim($content);

        return match ($format) {
            'pdf' => str_starts_with($content, '%PDF'),
            'html' => str_starts_with(mb_strtolower($trimmed), '<!doctype html')
                || str_starts_with(mb_strtolower($trimmed), '<html')
                || str_contains(mb_strtolower($trimmed), '<body'),
            'xml' => str_starts_with($trimmed, '<?xml')
                || str_contains($trimmed, '<dte:GTDocumento')
                || str_contains($trimmed, '<GTDocumento'),
            default => false,
        };
    }

    private function contentTypeForFormat(string $format): string
    {
        return match ($format) {
            'pdf' => 'application/pdf',
            'html' => 'text/html; charset=UTF-8',
            'xml' => 'application/xml',
            default => 'application/octet-stream',
        };
    }

    private function flattenResponse(array $payload, string $prefix = ''): array
    {
        $values = [];

        foreach ($payload as $key => $value) {
            $name = (string) $key;

            if (is_array($value)) {
                $values += $this->flattenResponse($value, $prefix.$name.'.');
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $values[$name] = $value;
                $values[$prefix.$name] = $value;
                $normalized = $this->normalizeResponseKey($name);
                $values[$normalized] = $value;
                $values[$this->normalizeResponseKey($prefix.$name)] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $keys
     */
    private function firstValue(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values) && filled($values[$key])) {
                return (string) $values[$key];
            }

            $normalized = $this->normalizeResponseKey($key);

            if (array_key_exists($normalized, $values) && filled($values[$normalized])) {
                return (string) $values[$normalized];
            }
        }

        return null;
    }

    private function normalizeResponseKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $key));
    }
}
