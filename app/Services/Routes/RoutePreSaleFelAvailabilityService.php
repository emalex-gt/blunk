<?php

namespace App\Services\Routes;

use App\Models\Branch;
use App\Models\Business;
use App\Models\TenantFelSetting;
use App\Models\TenantSetting;

class RoutePreSaleFelAvailabilityService
{
    public function evaluate(Business $business, ?Branch $branch = null): array
    {
        $settings = TenantSetting::query()->where('business_id', $business->id)->first();

        if ($business->country !== 'GT' || ! (bool) ($settings?->allow_invoices ?? false) || ! module_enabled('fel_gt', $business->id)) {
            return $this->unavailable('module_disabled', 'El módulo FEL no está activo para este negocio.');
        }

        $felSettings = TenantFelSetting::query()->where('business_id', $business->id)->first();

        if (! $felSettings || $felSettings->provider !== 'digifact') {
            return $this->unavailable('provider_missing', 'FEL no está configurado para certificación automática.');
        }

        if (! $felSettings->enabled || ! filled($felSettings->username) || ! filled($felSettings->password)) {
            return $this->unavailable('credentials_missing', 'FEL no está configurado para certificación automática.');
        }

        if (! filled($felSettings->issuer_tax_id)) {
            return $this->unavailable('issuer_missing', 'FEL no está configurado para certificación automática.');
        }

        if (! $felSettings->isConfigured()) {
            return $this->unavailable('unknown', 'FEL no está configurado para certificación automática.');
        }

        if ($branch && ! $this->hasFelEstablishmentData($branch)) {
            return $this->unavailable('document_series_missing', 'FEL no está configurado para certificación automática.');
        }

        return [
            'available' => true,
            'reason_code' => null,
            'reason' => null,
        ];
    }

    private function hasFelEstablishmentData(Branch $branch): bool
    {
        foreach ([
            'fel_establishment_code',
            'fel_establishment_name',
            'fel_address',
            'fel_postal_code',
            'fel_municipality',
            'fel_department',
            'fel_country',
        ] as $field) {
            if (! filled($branch->{$field})) {
                return false;
            }
        }

        return true;
    }

    private function unavailable(string $reasonCode, string $reason): array
    {
        return [
            'available' => false,
            'reason_code' => $reasonCode,
            'reason' => $reason,
        ];
    }
}
