<?php

namespace App\Support;

use App\Models\Business;
use App\Models\CustomerTaxLookup;
use App\Services\Fel\Providers\Digifact\DigifactClient;
use Illuminate\Validation\ValidationException;

class GuatemalaCuiCustomerResolver
{
    public const INVALID_CUI_MESSAGE = 'El CUI/DPI debe tener 13 dígitos.';

    public static function normalize(?string $cui): string
    {
        return CustomerIdentity::normalizeCui($cui);
    }

    public static function lookupTaxData(Business $business, ?string $cui, bool $allowCache = true): array
    {
        $normalizedCui = self::normalize($cui);

        if (! CustomerIdentity::isValidCui($normalizedCui)) {
            throw ValidationException::withMessages([
                'cui' => self::INVALID_CUI_MESSAGE,
                'customer.doc_number' => self::INVALID_CUI_MESSAGE,
            ]);
        }

        if ($allowCache) {
            $cache = CustomerTaxLookup::query()
                ->where('business_id', $business->id)
                ->where('country', 'GT')
                ->where('doc_type', CustomerIdentity::CUI)
                ->where('doc_number', $normalizedCui)
                ->first();

            if ($cache && $cache->last_lookup_at?->greaterThanOrEqualTo(now()->subDays(30))) {
                return [
                    'cui' => $cache->doc_number,
                    'name' => $cache->name,
                    'status' => 'A',
                    'raw' => $cache->raw_response,
                    'source' => 'cache',
                ];
            }
        }

        $result = DigifactClient::forBusiness($business)->lookupCui($normalizedCui);

        CustomerTaxLookup::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'country' => 'GT',
                'doc_type' => CustomerIdentity::CUI,
                'doc_number' => $result['cui'],
            ],
            [
                'name' => $result['name'],
                'provider' => 'digifact',
                'raw_response' => $result['raw'],
                'last_lookup_at' => now(),
            ],
        );

        return [
            ...$result,
            'source' => 'digifact',
        ];
    }
}
