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
            'address' => self::firstFilled(
                $branch?->address,
                $settings?->company_address,
            ),
            'phone' => self::firstFilled(
                $branch?->phone,
                $settings?->company_phone,
                $business?->phone,
            ),
        ];
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
