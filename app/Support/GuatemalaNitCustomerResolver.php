<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerTaxLookup;
use App\Services\Fel\FelException;
use App\Services\Fel\Providers\Digifact\DigifactClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class GuatemalaNitCustomerResolver
{
    public const INVALID_NIT_MESSAGE = 'Debes ingresar un NIT válido. No se permite CF para créditos.';
    public const LOOKUP_ERROR_MESSAGE = 'No se pudo validar el NIT. Verifica el número e inténtalo nuevamente.';

    public static function normalize(?string $nit): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', trim((string) $nit)));
    }

    public static function resolve(Business $business, ?string $nit, bool $allowCache = true): array
    {
        $normalizedNit = self::normalize($nit);

        if ($normalizedNit === '' || $normalizedNit === 'CF' || ! preg_match('/^[A-Z0-9]+$/', $normalizedNit)) {
            throw ValidationException::withMessages([
                'nit' => self::INVALID_NIT_MESSAGE,
                'to_customer_doc_number' => self::INVALID_NIT_MESSAGE,
            ]);
        }

        $existing = self::findExistingCustomer($business, $normalizedNit);

        if ($existing) {
            if ($existing->doc_type !== 'NIT') {
                $existing->forceFill(['doc_type' => 'NIT', 'doc_number' => $normalizedNit])->save();
            }

            return [
                'customer' => $existing->refresh(),
                'source' => 'existing',
                'nit' => $normalizedNit,
            ];
        }

        try {
            if ($allowCache) {
                $cache = CustomerTaxLookup::query()
                    ->where('business_id', $business->id)
                    ->where('country', 'GT')
                    ->where('doc_type', 'NIT')
                    ->where('doc_number', $normalizedNit)
                    ->first();

                if ($cache && $cache->last_lookup_at?->greaterThanOrEqualTo(now()->subDays(30))) {
                    return [
                        'customer' => self::saveVerifiedCustomer($business, $cache->doc_number, $cache->name, $cache->raw_response),
                        'source' => 'cache',
                        'nit' => $cache->doc_number,
                        'raw' => $cache->raw_response,
                    ];
                }
            }

            $result = DigifactClient::forBusiness($business)->lookupNit($normalizedNit);
            $raw = $result['raw'] ?? null;
            $nitResult = self::normalize($result['nit'] ?? $normalizedNit);
            $name = trim((string) ($result['name'] ?? ''));

            if ($name === '') {
                throw new FelException('Digifact no devolvió nombre para el NIT.');
            }

            CustomerTaxLookup::query()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'country' => 'GT',
                    'doc_type' => 'NIT',
                    'doc_number' => $nitResult,
                ],
                [
                    'name' => $name,
                    'provider' => 'digifact',
                    'raw_response' => $raw,
                    'last_lookup_at' => now(),
                ],
            );

            return [
                'customer' => self::saveVerifiedCustomer($business, $nitResult, $name, is_array($raw) ? $raw : null),
                'source' => 'digifact_created',
                'nit' => $nitResult,
                'raw' => $raw,
            ];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Guatemala NIT customer resolve failed', [
                'business_id' => $business->id,
                'nit' => $normalizedNit,
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'nit' => self::LOOKUP_ERROR_MESSAGE,
                'to_customer_doc_number' => self::LOOKUP_ERROR_MESSAGE,
            ]);
        }
    }

    private static function findExistingCustomer(Business $business, string $nit): ?Customer
    {
        return Customer::query()
            ->where('business_id', $business->id)
            ->whereRaw("UPPER(REPLACE(REPLACE(doc_number, '-', ''), ' ', '')) = ?", [$nit])
            ->where(function ($query) {
                $query->where('doc_type', 'NIT')->orWhereNull('doc_type');
            })
            ->first();
    }

    private static function saveVerifiedCustomer(Business $business, string $nit, string $name, ?array $raw): Customer
    {
        $customer = self::findExistingCustomer($business, $nit) ?? new Customer([
            'business_id' => $business->id,
            'doc_number' => $nit,
        ]);
        $address = self::extractResponseValue($raw, ['Direccion', 'DIRECCION', 'direccion', 'Address']);
        $department = self::extractResponseValue($raw, ['DEPARTAMENTO', 'Departamento', 'department']);
        $municipality = self::extractResponseValue($raw, ['MUNICIPIO', 'Municipio', 'municipality']);

        $customer->forceFill([
            'name' => $name,
            'doc_type' => 'NIT',
            'doc_number' => $nit,
            'address' => $address ?: $customer->address,
            'department' => $department ?: $customer->department,
            'municipality' => $municipality ?: $customer->municipality,
            'country' => 'GT',
            'is_final_consumer' => false,
            'name_locked' => true,
            'tax_lookup_payload' => $raw,
            'tax_lookup_verified_at' => now(),
        ])->save();

        return $customer;
    }

    private static function extractResponseValue(?array $payload, array $keys): ?string
    {
        if (! $payload) {
            return null;
        }

        if (isset($payload['RESPONSE']) && is_array($payload['RESPONSE'])) {
            foreach ($payload['RESPONSE'] as $row) {
                if (is_array($row) && ($value = self::extractResponseValue($row, $keys))) {
                    return $value;
                }
            }
        }

        foreach ($payload as $key => $value) {
            if (is_array($value) && ($nested = self::extractResponseValue($value, $keys))) {
                return $nested;
            }

            if (in_array((string) $key, $keys, true) && filled($value) && is_scalar($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }
}
