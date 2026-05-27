<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Business;

class BusinessLogo
{
    public static function forPrint(Business $business, ?Branch $branch = null): ?string
    {
        $businessLogo = $business->logo_url ?: $business->tenantSetting?->company_logo_url;

        if (! module_enabled('branches', (int) $business->id)) {
            return $businessLogo;
        }

        return $branch?->logo_url ?: $businessLogo;
    }
}
