<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\TenantSetting;

class DocumentCompanyHeader
{
    public static function make(?Business $business, ?Branch $branch = null, ?TenantSetting $settings = null): array
    {
        return [
            'logo_url' => $business ? BusinessLogo::forPrint($business, $branch) : null,
            'name' => self::firstFilled(
                $business?->name,
                $settings?->company_name,
            ) ?: 'Empresa',
            'address' => self::fullAddress($business, $branch, $settings),
            'phone' => self::firstFilled(
                $branch?->phone,
                $settings?->company_phone,
                $business?->phone,
            ),
        ];
    }

    private static function fullAddress(?Business $business, ?Branch $branch = null, ?TenantSetting $settings = null): ?string
    {
        $branchParts = self::filledParts(
            $branch?->address,
            $branch?->municipality,
            $branch?->department,
        );

        if ($branchParts !== []) {
            return implode(', ', $branchParts);
        }

        $fallbackParts = self::filledParts(
            self::firstFilled($business?->address, $settings?->company_address),
            $business?->municipality,
            $business?->department,
        );

        return $fallbackParts === [] ? null : implode(', ', $fallbackParts);
    }

    private static function filledParts(mixed ...$values): array
    {
        return array_values(array_filter(
            array_map(fn (mixed $value): ?string => self::firstFilled($value), $values),
            fn (?string $part): bool => $part !== null,
        ));
    }

    private static function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) ($value ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
