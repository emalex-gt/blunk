<?php

namespace App\Services\Fel\Providers\Digifact;

use App\Models\Business;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Models\TenantFelSetting;
use App\Services\Fel\FelException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DigifactClient
{
    private string $lastTokenSource = 'unknown';
    private array $timings = [];
    private int $tokenRequestCount = 0;

    public function __construct(
        private readonly Business $business,
        private readonly TenantFelSetting $settings,
    ) {
    }

    public static function forBusiness(Business $business, ?TenantFelSetting $settings = null): self
    {
        $settings ??= TenantFelSetting::query()->firstOrCreate(
                ['business_id' => $business->id],
                [
                    'provider' => 'digifact',
                    'environment' => 'test',
                    'enabled' => false,
                    'test_base_url' => config('digifact.test_base_url'),
                    'production_base_url' => config('digifact.production_base_url'),
                ],
            );

        $updates = [];

        if (! filled($settings->test_base_url)) {
            $updates['test_base_url'] = config('digifact.test_base_url');
        }

        if (! filled($settings->production_base_url)) {
            $updates['production_base_url'] = config('digifact.production_base_url');
        }

        if ($updates !== []) {
            $settings->forceFill($updates)->save();
        }

        return new self($business, $settings);
    }

    public function getToken(?TenantFelSetting $settings = null): string
    {
        $started = microtime(true);
        $settings ??= $this->settings;
        $this->ensureGuatemala();
        $this->ensureHasBaseUrl();

        if ($settings->token && $settings->token_expires_at?->gt(now()->addMinutes(2))) {
            $this->lastTokenSource = 'cached';
            $this->addTiming('token_ms', $this->elapsedMilliseconds($started));
            $this->timings['token_source'] = $this->lastTokenSource;

            Log::info('Digifact token reused', [
                'business_id' => $this->business->id,
                'environment' => $settings->environment,
                'token_source' => $this->lastTokenSource,
                'token_ms' => $this->timings['token_ms'],
                'expires_at' => $settings->token_expires_at?->toIso8601String(),
            ]);

            return $settings->token;
        }

        $this->tokenRequestCount++;

        if (! $settings->issuer_tax_id || ! $settings->username || ! $settings->password) {
            throw new FelException('Configura NIT emisor, usuario y password de Digifact.');
        }

        Log::info('Digifact token request start', [
            'business_id' => $this->business->id,
            'environment' => $settings->environment,
            'base_url' => $this->baseUrl(),
            'endpoint' => $this->endpoint('token'),
            'full_url' => $this->buildUrl($this->endpoint('token')),
            'issuer_tax_id' => DigifactNit::normalizeIssuerTaxId($settings->issuer_tax_id),
            'username_part' => $settings->username,
        ]);

        $response = Http::connectTimeout((int) config('digifact.connect_timeout', 5))
            ->timeout((int) config('digifact.token_timeout', 10))
            ->acceptJson()
            ->asJson()
            ->post($this->buildUrl($this->endpoint('token')), [
                'Username' => $this->digifactUsername($settings),
                'Password' => $settings->password,
            ]);

        if (! $response->successful()) {
            $tokenMs = $this->elapsedMilliseconds($started);

            Log::warning('Digifact token request failed', [
                'business_id' => $this->business->id,
                'http_status' => $response->status(),
                'token_ms' => $tokenMs,
                'body_preview' => mb_substr(trim(strip_tags($response->body())), 0, 500),
                'parsed_json' => $this->decodedResponse($response, false),
            ]);

            $message = $this->readableError($response) ?: 'respuesta no legible del proveedor';

            throw new FelException("No se pudo obtener token Digifact: {$message}");
        }

        $payload = $this->decodedResponse($response, false);
        $token = is_string($payload)
            ? trim($payload, " \t\n\r\0\x0B\"")
            : $this->extractToken(is_array($payload) ? $payload : []);

        if (! $token) {
            throw new FelException('Digifact no devolvio token.');
        }

        $settings->forceFill([
            'token' => $token,
            'token_expires_at' => is_array($payload) ? $this->extractTokenExpiration($payload) : now()->addMinutes(50),
            'last_error' => null,
        ])->save();

        $this->lastTokenSource = 'refreshed';
        $this->addTiming('token_ms', $this->elapsedMilliseconds($started));
        $this->timings['token_source'] = $this->lastTokenSource;

        Log::info('Digifact token request success', [
            'business_id' => $this->business->id,
            'http_status' => $response->status(),
            'expires_at' => $settings->token_expires_at?->toIso8601String(),
            'token_source' => $this->lastTokenSource,
            'token_ms' => $this->timings['token_ms'],
        ]);

        return $token;
    }

    public function lastTokenSource(): string
    {
        return $this->lastTokenSource;
    }

    public function timingSummary(): array
    {
        return [
            ...$this->timings,
            'token_source' => $this->lastTokenSource,
            'token_refresh_count' => $this->tokenRequestCount,
        ];
    }

    public function lookupNit(string $nit): array
    {
        $this->ensureReadyForCalls();
        $receiverNit = DigifactNit::cleanReceiverNit($nit);

        if ($receiverNit === 'CF') {
            throw new FelException('Ingresa un NIT valido para consultar.');
        }

        $query = $this->sharedLookupQuery($receiverNit);
        $token = $this->getToken();

        Log::info('Digifact NIT lookup request', [
            'business_id' => $this->business->id,
            'environment' => $this->settings->environment,
            'base_url' => $this->baseUrl(),
            'endpoint' => $this->endpoint('shared'),
            'full_url' => $this->buildUrl($this->endpoint('shared')),
            'method' => 'GET',
            'query' => $query,
            'issuer_tax_id' => $this->issuerTaxId(),
            'username' => $this->settings->username,
            'nit_to_lookup' => $receiverNit,
            'has_token' => filled($token),
        ]);

        $started = microtime(true);
        $response = $this->authorizedRequest((int) config('digifact.lookup_timeout', 10))
            ->get($this->buildUrl($this->endpoint('shared')), $query);

        if ($response->status() === 401) {
            $this->settings->forceFill(['token' => null, 'token_expires_at' => null])->save();
            $response = $this->authorizedRequest((int) config('digifact.lookup_timeout', 10))
                ->get($this->buildUrl($this->endpoint('shared')), $query);
        }

        Log::info('Digifact NIT lookup response', [
            'business_id' => $this->business->id,
            'status' => $response->status(),
            'lookup_ms' => $this->recordTiming('lookup_ms', $started),
            'token_source' => $this->lastTokenSource(),
            'parsed_json' => $this->safeLogPayload($this->decodedResponse($response, false)),
        ]);

        if (! $response->successful()) {
            throw new FelException('No se pudo consultar el NIT: '.($this->readableError($response) ?: 'respuesta no legible del proveedor'));
        }

        $payload = $this->decodedResponse($response);

        if (! is_array($payload)) {
            throw new FelException('La respuesta de Digifact no es valida.');
        }

        if (! $this->lookupResponseHasData($payload)) {
            $message = $this->readableErrorFromArray($payload) ?: 'NIT no encontrado o no válido.';

            throw new FelException("No se pudo consultar el NIT: {$message}");
        }

        $name = $this->extractNitName($payload);

        if (! $name) {
            throw new FelException('Digifact respondio correctamente, pero no se encontro el nombre en la respuesta.');
        }

        Log::info('Digifact NIT lookup success', [
            'business_id' => $this->business->id,
            'receiver_nit' => $receiverNit,
            'has_name' => true,
        ]);

        return [
            'nit' => $receiverNit,
            'name' => $name,
            'raw' => $payload,
        ];
    }

    public function certify(array $payload, string $format = 'XML'): array
    {
        $this->ensureReadyForCalls();

        Log::info('Digifact certification request sent', [
            'business_id' => $this->business->id,
            'environment' => $this->settings->environment,
            'endpoint' => $this->endpoint('certify_invoice'),
            'full_url' => $this->buildUrl($this->endpoint('certify_invoice')),
            'method' => 'POST',
            'issuer_tax_id' => $this->issuerTaxId(),
            'username_part' => $this->settings->username,
            'format' => $format,
        ]);

        return $this->postAuthenticatedWithRetry($this->endpoint('certify_invoice'), $payload, [
            'TAXID' => $this->issuerTaxId(),
            'USERNAME' => $this->settings->username,
            'FORMAT' => $format,
        ], (int) config('digifact.certification_timeout', 20), 'certification_ms');
    }

    public function findDocumentByInternalReference(
        TenantFelSetting $settings,
        string $internalReference,
        Carbon|string $issuedAt,
    ): array {
        $this->ensureReadyForCalls();

        $issuedDate = $issuedAt instanceof Carbon
            ? $issuedAt->copy()->timezone('America/Guatemala')->format('Y-m-d\TH:i:s')
            : trim($issuedAt);

        $query = [
            'COUNTRY' => 'GT',
            'TAXID' => DigifactNit::padIssuerNitForApi($settings->issuer_tax_id),
            'DATA1' => 'SHARED_GETDTEINFO_BY_INTERNALID',
            'DATA2' => "REFERENCIA_INTERNA|{$internalReference}|ISSUEDDATE|{$issuedDate}",
            'USERNAME' => $settings->username,
        ];

        Log::info('Digifact internal reference reconciliation request', [
            'business_id' => $this->business->id,
            'environment' => $settings->environment,
            'endpoint' => $this->endpoint('shared'),
            'full_url' => $this->buildUrl($this->endpoint('shared')),
            'method' => 'GET',
            'query' => $query,
            'issuer_tax_id' => $query['TAXID'],
            'username' => $settings->username,
            'internal_reference' => $internalReference,
            'issued_date' => $issuedDate,
        ]);

        $response = $this->authorizedRequest((int) config('digifact.lookup_timeout', 10))->get($this->buildUrl($this->endpoint('shared')), $query);

        if ($response->status() === 401) {
            $this->settings->forceFill(['token' => null, 'token_expires_at' => null])->save();
            $response = $this->authorizedRequest((int) config('digifact.lookup_timeout', 10))->get($this->buildUrl($this->endpoint('shared')), $query);
        }

        Log::info('Digifact internal reference reconciliation response', [
            'business_id' => $this->business->id,
            'status' => $response->status(),
            'token_source' => $this->lastTokenSource(),
            'parsed_json' => $this->safeLogPayload($this->decodedResponse($response, false)),
        ]);

        if (! $response->successful()) {
            throw new FelException($this->readableError($response) ?: 'No se pudo consultar la referencia interna en Digifact.');
        }

        $payload = $this->decodedResponse($response);

        if (! is_array($payload)) {
            throw new FelException('La respuesta de Digifact no es valida.');
        }

        if (! $this->lookupResponseHasData($payload)) {
            return [];
        }

        return $payload;
    }

    public function cancel(array $payload, string $format = 'XML'): array
    {
        $this->ensureReadyForCalls();

        Log::info('Digifact cancellation request sent', [
            'business_id' => $this->business->id,
            'environment' => $this->settings->environment,
            'endpoint' => $this->endpoint('cancel_invoice'),
            'full_url' => $this->buildUrl($this->endpoint('cancel_invoice')),
            'method' => 'POST',
            'issuer_tax_id' => $this->issuerTaxId(),
            'username_part' => $this->settings->username,
        ]);

        // TODO: Confirm the final Digifact GT cancellation path from the active NUC API contract.
        // The endpoint remains isolated here so the controller/service flow does not change.
        return $this->postAuthenticatedWithRetry($this->endpoint('cancel_invoice'), $payload, [
            'TAXID' => $this->issuerTaxId(),
            'USERNAME' => $this->settings->username,
            'FORMAT' => $format,
        ], (int) config('digifact.certification_timeout', 20), 'cancellation_ms');
    }

    public function getDocument(ElectronicDocument|Sale $document, string $format): array|string
    {
        $this->ensureReadyForCalls();
        $authNumber = $document instanceof Sale ? $document->fel_uuid : $document->uuid;

        Log::info('Digifact GetDocument request sent', [
            'business_id' => $this->business->id,
            'environment' => $this->settings->environment,
            'endpoint' => $this->endpoint('get_document'),
            'full_url' => $this->buildUrl($this->endpoint('get_document')),
            'method' => 'GET',
            'issuer_tax_id' => $this->issuerTaxId(),
            'auth_number' => $authNumber,
            'format_requested' => strtoupper($format),
        ]);

        $started = microtime(true);
        $timeout = strtoupper($format) === 'HTML'
            ? (int) config('digifact.document_timeout', 15)
            : (int) config('digifact.certification_timeout', 20);
        $response = $this->authorizedDownloadRequest($timeout)
            ->get($this->buildUrl($this->endpoint('get_document')), [
                'AUTHNUMBER' => $authNumber,
                'TAXID' => $this->issuerTaxId(),
                'FORMAT' => strtoupper($format),
                'USERNAME' => $this->settings->username,
            ]);

        if ($response->status() === 401) {
            $this->settings->forceFill(['token' => null, 'token_expires_at' => null])->save();
            $response = $this->authorizedDownloadRequest($timeout)
                ->get($this->buildUrl($this->endpoint('get_document')), [
                    'AUTHNUMBER' => $authNumber,
                    'TAXID' => $this->issuerTaxId(),
                    'FORMAT' => strtoupper($format),
                    'USERNAME' => $this->settings->username,
                ]);
        }

        $contentType = (string) $response->header('Content-Type', '');
        $responseBody = ltrim($response->body());
        $looksJson = str_contains(mb_strtolower($contentType), 'application/json')
            || str_starts_with($responseBody, '{')
            || str_starts_with($responseBody, '[');
        $documentTimingKey = 'get_document_'.strtolower($format).'_ms';
        $documentMs = $this->recordTiming($documentTimingKey, $started);
        $this->timings['get_document_'.strtolower($format).'_http_status'] = $response->status();

        Log::info('Digifact GetDocument response received', [
            'business_id' => $this->business->id,
            'endpoint' => $this->endpoint('get_document'),
            'http_status' => $response->status(),
            'format_requested' => strtoupper($format),
            'content_type' => $contentType,
            'token_source' => $this->lastTokenSource(),
            $documentTimingKey => $documentMs,
            'response_kind' => $looksJson ? 'json' : 'document',
            'parsed_json' => $looksJson ? $this->safeLogPayload($this->decodedResponse($response, false)) : null,
            'error_body_preview' => $response->successful() ? null : mb_substr(trim(strip_tags($response->body())), 0, 500),
        ]);

        if (! $response->successful()) {
            throw new FelException($this->readableError($response) ?: 'No se pudo obtener el documento certificado.');
        }

        $body = $response->body();
        $contentType = (string) $response->header('Content-Type', '');

        if (str_contains(mb_strtolower($contentType), 'application/pdf') || str_starts_with($body, '%PDF')) {
            return [
                'content_type' => $contentType ?: 'application/pdf',
                'body_base64' => base64_encode($body),
            ];
        }

        $trimmedBody = ltrim($body);
        $lowerContentType = mb_strtolower($contentType);
        $lowerTrimmedBody = mb_strtolower($trimmedBody);

        if (str_contains($lowerContentType, 'text/html')
            || str_starts_with($lowerTrimmedBody, '<!doctype html')
            || str_starts_with($lowerTrimmedBody, '<html')
            || str_contains($lowerTrimmedBody, '<body')
            || str_contains($lowerContentType, 'xml')
            || str_starts_with($trimmedBody, '<?xml')
        ) {
            return $body;
        }

        return $this->decodedResponse($response, false) ?? $body;
    }

    public function testConnection(): void
    {
        $this->ensureGuatemala();
        $this->ensureHasBaseUrl();
        $this->getToken();
    }

    public function configurationDiagnostics(): array
    {
        return [
            'business_id' => $this->business->id,
            'business_country' => $this->business->country,
            'has_fel_settings' => $this->settings->exists,
            'enabled' => $this->settings->enabled,
            'provider' => $this->settings->provider,
            'environment' => $this->settings->environment,
            'has_issuer_tax_id' => filled($this->settings->issuer_tax_id),
            'has_username' => filled($this->settings->username),
            'has_password' => filled($this->settings->password),
            'has_test_base_url' => filled($this->settings->test_base_url),
            'has_production_base_url' => filled($this->settings->production_base_url),
            'configured' => $this->settings->isConfigured(),
            'missing_fields' => $this->settings->missingConfigurationFields(),
            'active_base_url' => $this->settings->baseUrl(),
        ];
    }

    public function debugLookupNit(string $nit): array
    {
        $diagnostics = $this->configurationDiagnostics();

        if (! $this->settings->isConfigured()) {
            return [
                'diagnostics' => $diagnostics,
                'configuration_error' => $this->settings->configurationErrorMessage(),
            ];
        }

        $this->ensureReadyForCalls();
        $receiverNit = DigifactNit::cleanReceiverNit($nit);
        $query = $this->sharedLookupQuery($receiverNit);

        $response = $this->authorizedRequest((int) config('digifact.lookup_timeout', 10))->get($this->buildUrl($this->endpoint('shared')), $query);

        if ($response->status() === 401) {
            $this->settings->forceFill(['token' => null, 'token_expires_at' => null])->save();
            $response = $this->authorizedRequest((int) config('digifact.lookup_timeout', 10))->get($this->buildUrl($this->endpoint('shared')), $query);
        }

        $parsed = $this->decodedResponse($response);

        return [
            'diagnostics' => $diagnostics,
            'settings' => [
                'business_id' => $this->business->id,
                'country' => $this->business->country,
                'provider' => $this->settings->provider,
                'environment' => $this->settings->environment,
                'enabled' => $this->settings->enabled,
                'base_url' => $this->baseUrl(),
                'issuer_tax_id_padded' => $this->issuerTaxId(),
                'username' => $this->settings->username,
                'has_token' => filled($this->settings->fresh()->token),
                'token_expires_at' => $this->settings->fresh()->token_expires_at?->toIso8601String(),
            ],
            'request' => [
                'url' => $this->buildUrl($this->endpoint('shared')).'?'.http_build_query($query),
                'query' => $query,
            ],
            'response' => [
                'status' => $response->status(),
                'raw_body' => $response->body(),
                'parsed' => $parsed,
            ],
            'extracted_name' => is_array($parsed) ? $this->extractNitName($parsed) : null,
            'readable_error' => is_array($parsed) ? $this->readableErrorFromArray($parsed) : $this->readableError($response),
        ];
    }

    private function postAuthenticatedWithRetry(string $endpoint, array $payload, array $query, int $timeout = 20, string $durationKey = 'request_ms'): array
    {
        $started = microtime(true);
        $url = $this->buildUrl($endpoint);
        $response = $this->authorizedRequest($timeout)->post($url.'?'.http_build_query($query), $payload);

        if ($response->status() === 401) {
            $this->settings->forceFill(['token' => null, 'token_expires_at' => null])->save();
            $response = $this->authorizedRequest($timeout)->post($url.'?'.http_build_query($query), $payload);
        }

        $requestMs = $this->recordTiming($durationKey, $started);
        $this->timings[str_replace('_ms', '_http_status', $durationKey)] = $response->status();

        Log::info('Digifact response received', [
            'business_id' => $this->business->id,
            'endpoint' => $endpoint,
            'full_url' => $url,
            'method' => 'POST',
            'query' => $query,
            'http_status' => $response->status(),
            'format_requested' => $query['FORMAT'] ?? null,
            'token_source' => $this->lastTokenSource(),
            $durationKey => $requestMs,
            'parsed_json' => $this->safeLogPayload($this->decodedResponse($response, false)),
        ]);

        if (! $response->successful()) {
            $payload = $this->decodedResponse($response, false);

            throw new FelException(
                $this->readableError($response) ?: 'Digifact rechazo la solicitud.',
                responsePayload: is_array($payload) ? $payload : null,
            );
        }

        $data = $this->decodedResponse($response);

        if (! is_array($data)) {
            throw new FelException('La respuesta de Digifact no es valida.');
        }

        if (! $this->isSuccessfulBusinessResponse($data)) {
            throw new FelException(
                $this->readableErrorFromArray($data) ?: 'Digifact rechazo la solicitud.',
                responsePayload: $data,
            );
        }

        return $data;
    }

    private function authorizedRequest(int $timeout = 10)
    {
        return Http::connectTimeout((int) config('digifact.connect_timeout', 5))
            ->timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'Authorization' => $this->getToken(),
                'Content-Type' => 'application/json',
            ]);
    }

    private function authorizedDownloadRequest(int $timeout = 15)
    {
        return Http::connectTimeout((int) config('digifact.connect_timeout', 5))
            ->timeout($timeout)
            ->accept('*/*')
            ->withHeaders([
                'Authorization' => $this->getToken(),
            ]);
    }

    private function endpoint(string $key): string
    {
        return ltrim((string) config("digifact.endpoints.{$key}"), '/');
    }

    private function sharedLookupQuery(string $receiverNit): array
    {
        return [
            'COUNTRY' => 'GT',
            'TAXID' => $this->issuerTaxId(),
            'DATA1' => 'SHARED_GETINFONITcom',
            'DATA2' => "NIT|{$receiverNit}",
            'USERNAME' => $this->settings->username,
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->settings->baseUrl(), '/');
    }

    private function buildUrl(string $path): string
    {
        $base = $this->baseUrl();
        $path = ltrim($path, '/');

        if (str_ends_with($base, '/api') && str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        return $base.'/'.$path;
    }

    private function issuerTaxId(): string
    {
        return DigifactNit::padIssuerNitForApi($this->settings->issuer_tax_id);
    }

    private function digifactUsername(TenantFelSetting $settings): string
    {
        return 'GT.'.$this->issuerTaxId().'.'.$settings->username;
    }

    private function ensureReadyForCalls(): void
    {
        $this->ensureGuatemala();

        if (! $this->settings->isConfigured()) {
            throw new FelException($this->settings->configurationErrorMessage());
        }
    }

    private function ensureGuatemala(): void
    {
        if ($this->business->country !== 'GT') {
            throw new FelException('La facturacion electronica FEL esta disponible solo para Guatemala.');
        }
    }

    private function ensureHasBaseUrl(): void
    {
        if (! $this->settings->baseUrl()) {
            throw new FelException('La URL de Digifact no esta configurada.');
        }
    }

    private function extractToken(array $payload): ?string
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $token = $this->extractToken($value);

                if ($token) {
                    return $token;
                }
            }

            if (in_array((string) $key, ['Token', 'token', 'TOKEN', 'access_token', 'Authorization'], true)
                && filled($value)
                && is_scalar($value)
            ) {
                return (string) $value;
            }
        }

        return null;
    }

    private function extractTokenExpiration(array $payload): Carbon
    {
        foreach (['Expiration', 'expiration', 'Expires', 'expires', 'expires_at'] as $key) {
            if (! empty($payload[$key]) && is_scalar($payload[$key])) {
                try {
                    return Carbon::parse((string) $payload[$key]);
                } catch (\Throwable) {
                    break;
                }
            }
        }

        return now()->addMinutes(50);
    }

    private function elapsedMilliseconds(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }

    private function addTiming(string $key, float $milliseconds): void
    {
        $this->timings[$key] = round((float) ($this->timings[$key] ?? 0) + $milliseconds, 2);
    }

    private function recordTiming(string $key, float $started): float
    {
        $duration = $this->elapsedMilliseconds($started);
        $this->timings[$key] = $duration;

        return $duration;
    }

    private function lookupResponseHasData(array $payload): bool
    {
        if (! array_key_exists('RESPONSE', $payload)) {
            return true;
        }

        $response = $payload['RESPONSE'];

        return is_array($response) && count($response) > 0;
    }

    private function extractNitName(array $payload): ?string
    {
        if (isset($payload['RESPONSE']) && is_array($payload['RESPONSE'])) {
            foreach ($payload['RESPONSE'] as $row) {
                if (is_array($row) && ($name = $this->extractNitName($row))) {
                    return $name;
                }
            }
        }

        foreach ($payload as $key => $rawValue) {
            if ((string) $key === 'REQUEST_DATA') {
                continue;
            }

            if (is_array($rawValue)) {
                $name = $this->extractNitName($rawValue);

                if ($name) {
                    return $name;
                }
            }

            if (in_array((string) $key, ['Nombre', 'nombre', 'NOMBRE', 'name', 'RAZON_SOCIAL', 'razon_social', 'RazonSocial', 'NombreCompleto', 'ResponseData1', 'ResponseDATA1', 'RESPONSE_DATA1'], true)
                && filled($rawValue)
                && is_scalar($rawValue)
            ) {
                $value = trim((string) $rawValue);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function isSuccessfulBusinessResponse(array $payload): bool
    {
        if (isset($payload['REQUEST_DATA']) && is_array($payload['REQUEST_DATA'])) {
            foreach ($payload['REQUEST_DATA'] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $code = $row['Codigo'] ?? $row['codigo'] ?? null;

                if (filled($code) && ! in_array((string) $code, ['1', '200'], true)) {
                    return false;
                }
            }
        }

        if (! array_key_exists('Codigo', $payload) && ! array_key_exists('codigo', $payload)) {
            return true;
        }

        $code = (string) ($payload['Codigo'] ?? $payload['codigo']);

        return in_array($code, ['1', '200'], true);
    }

    private function readableError(Response $response): ?string
    {
        if ($response->status() === 401) {
            return 'Credenciales Digifact inválidas o token vencido.';
        }

        if ($response->status() === 403) {
            return 'No tienes autorización para consultar Digifact.';
        }

        if ($response->status() === 404) {
            return 'No se encontro el servicio de Digifact. Verifica URL, ambiente y endpoint configurado.';
        }

        if ($response->status() >= 500) {
            return 'Digifact no respondió correctamente. Intenta nuevamente.';
        }

        $json = $this->decodedResponse($response, false);

        if (is_array($json)) {
            return $this->readableErrorFromArray($json);
        }

        $body = trim(strip_tags($response->body()));

        return $body !== '' ? mb_substr($body, 0, 500) : null;
    }

    private function readableErrorFromArray(array $payload): ?string
    {
        if (isset($payload['REQUEST_DATA']) && is_array($payload['REQUEST_DATA'])) {
            foreach ($payload['REQUEST_DATA'] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $parts = [];

                foreach (['Mensaje', 'Descripcion', 'Codigo'] as $key) {
                    if (filled($row[$key] ?? null) && is_scalar($row[$key])) {
                        $parts[] = (string) $row[$key];
                    }
                }

                if ($parts !== []) {
                    $message = implode(' - ', $parts);

                    return str_contains(mb_strtolower($message), 'nit')
                        ? 'NIT no encontrado o no válido.'
                        : $message;
                }
            }
        }

        foreach ($payload as $key => $value) {
            if (in_array((string) $key, ['Mensaje', 'mensaje', 'message', 'Error', 'error', 'Descripcion', 'descripcion', 'Codigo', 'codigo', 'CodigosSAT'], true)
                && filled($value)
            ) {
                return is_scalar($value) ? (string) $value : json_encode($value);
            }

            if (is_array($value)) {
                $message = $this->readableErrorFromArray($value);

                if ($message) {
                    return $message;
                }
            }
        }

        return null;
    }

    private function safeLogPayload(array|string|null $payload): array|string|null
    {
        if (! is_array($payload)) {
            return $payload;
        }

        return $this->removeResponseDataPayloads($payload);
    }

    private function removeResponseDataPayloads(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['responsedata1', 'responsedata2', 'responsedata3', 'responsedata4'], true)) {
                $clean["has_{$normalized}"] = filled($value);
                continue;
            }

            $clean[$key] = is_array($value) ? $this->removeResponseDataPayloads($value) : $value;
        }

        return $clean;
    }

    private function decodedResponse(Response $response, bool $throwOnInvalid = true): array|string|null
    {
        $body = trim($response->body());

        if ($body === '') {
            return null;
        }

        $contentType = (string) $response->header('Content-Type', '');
        $looksJson = str_contains(mb_strtolower($contentType), 'application/json')
            || str_starts_with($body, '{')
            || str_starts_with($body, '[');

        if (! $looksJson) {
            $preview = mb_substr(trim(strip_tags($body)), 0, 500);

            Log::warning('Digifact returned non-JSON response', [
                'business_id' => $this->business->id,
                'status' => $response->status(),
                'content_type' => $contentType,
                'body_preview' => $preview,
            ]);

            if ($throwOnInvalid) {
                throw new FelException('Digifact devolvió una respuesta no válida. Verifica la configuración FEL o intenta nuevamente.');
            }

            return $preview !== '' ? $preview : null;
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            Log::warning('Digifact JSON parse failed', [
                'business_id' => $this->business->id,
                'status' => $response->status(),
                'content_type' => $contentType,
                'body_preview' => mb_substr($body, 0, 500),
            ]);

            if ($throwOnInvalid) {
                throw new FelException('No se pudo leer la respuesta de Digifact.');
            }

            return null;
        }
    }
}
