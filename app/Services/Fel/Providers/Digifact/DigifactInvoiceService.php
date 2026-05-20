<?php

namespace App\Services\Fel\Providers\Digifact;

use App\Models\Business;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Models\TenantFelSetting;
use App\Services\Fel\FelException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DigifactInvoiceService
{
    public function __construct(
        private readonly DigifactNucJsonBuilder $payloadBuilder,
    ) {
    }

    public function certifySale(Sale $sale): ElectronicDocument
    {
        $sale->loadMissing(['business', 'customer', 'items.product', 'payments', 'electronicDocument']);
        $business = $sale->business ?: Business::query()->find($sale->business_id);
        $settings = $this->settings($business);
        $document = $sale->electronicDocument ?: $this->createPendingDocument($sale, $settings);
        $payload = $this->payloadBuilder->buildInvoicePayload($sale, $settings);
        $this->validateInvoicePayload($payload, $settings);

        $document->update([
            'status' => 'pending',
            'request_payload' => $payload,
            'error_message' => null,
        ]);

        Log::info('Digifact certification start', [
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'electronic_document_id' => $document->id,
            'environment' => $settings->environment,
            'payload_json' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        try {
            $this->writeDebugPayload($payload);

            Log::info('DIGIFACT SELLER JSON', [
                'seller' => $payload['Seller'] ?? null,
                'seller_json' => json_encode($payload['Seller'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                'api_taxid_padded' => DigifactNit::padIssuerNitForApi($settings->issuer_tax_id),
                'payload_seller_taxid' => $payload['Seller']['TaxID'] ?? null,
            ]);

            Log::info('DIGIFACT BUYER JSON', [
                'buyer' => $payload['Buyer'] ?? null,
                'buyer_json' => json_encode($payload['Buyer'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ]);

            Log::info('DIGIFACT FINAL PAYLOAD JSON', [
                'payload_json' => json_encode($payload, JSON_PRETTY_PRINT),
            ]);

            $response = DigifactClient::forBusiness($business)->certify($payload, 'XML|HTML|PDF');
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

            $document->update([
                'status' => 'certified',
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

            $document = $document->refresh();

            $sale->update([
                'electronic_document_id' => $document->id,
                'certification_status' => 'certified',
                'fel_uuid' => $normalized['uuid'],
                'fel_series' => $normalized['series'],
                'fel_number' => $normalized['number'],
                'fel_xml_path' => null,
                'fel_html_path' => null,
                'fel_pdf_url' => null,
                'fel_pdf_path' => null,
                'fel_certified_at' => $normalized['certification_date'],
                'fel_issued_at' => $normalized['issued_at'],
                'fel_status' => 'CERTIFIED',
                'fel_raw_response' => $sanitizedResponse,
            ]);

            Log::info('Digifact certification success', [
                'business_id' => $business->id,
                'sale_id' => $sale->id,
                'electronic_document_id' => $document->id,
                'has_uuid' => filled($normalized['uuid']),
            ]);

            return $document->refresh();
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

            $sale->update([
                'electronic_document_id' => $document->id,
                'certification_status' => 'failed',
            ]);

            Log::error('Digifact certification failed', [
                'business_id' => $business->id,
                'sale_id' => $sale->id,
                'electronic_document_id' => $document->id,
                'error' => $message,
            ]);

            throw new FelException($message, previous: $exception);
        }
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

    public function getDocumentContent(Sale $sale, string $format): array
    {
        $sale->loadMissing(['business', 'electronicDocument']);
        $business = $sale->business ?: Business::query()->findOrFail($sale->business_id);
        $document = $sale->electronicDocument;
        $format = strtoupper($format);

        if (! $document || ! filled($document->uuid)) {
            throw new FelException('La factura FEL no tiene autorizacion para consultar el documento.');
        }

        $response = DigifactClient::forBusiness($business)->getDocument($document, $format);
        $content = $this->extractDocumentContent($response, $format);

        if (! $content) {
            throw new FelException('No se encontro el documento imprimible en Digifact.');
        }

        return $content;
    }

    private function extractDocumentContent(array|string $response, string $format): ?array
    {
        if (is_string($response)) {
            $detected = $this->detectEncodedOrRawDocument($response);

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
            return null;
        }

        $content = base64_decode((string) $base64, true);

        if ($content === false || ! $this->contentMatchesFormat($content, strtolower($format))) {
            return null;
        }

        return [
            'content' => $content,
            'content_type' => $this->contentTypeForFormat(strtolower($format)),
            'extension' => strtolower($format),
        ];
    }

    private function sanitizeProviderResponse(array $response): array
    {
        $sanitized = $this->removeResponseDataPayloads($response);

        $flat = $this->flattenResponse($response);
        $sanitized['has_xml'] = filled($this->firstValue($flat, ['responseData1', 'ResponseData1', 'RESPONSE.0.ResponseData1']));
        $sanitized['has_html'] = filled($this->firstValue($flat, ['responseData2', 'ResponseData2', 'RESPONSE.0.ResponseData2']));
        $sanitized['has_pdf'] = filled($this->firstValue($flat, ['responseData3', 'ResponseData3', 'RESPONSE.0.ResponseData3', 'pdf', 'PDF']));

        return $sanitized;
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
