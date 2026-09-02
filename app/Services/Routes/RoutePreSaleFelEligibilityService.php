<?php

namespace App\Services\Routes;

use App\Models\PreSale;
use Illuminate\Validation\ValidationException;

class RoutePreSaleFelEligibilityService
{
    public function evaluate(PreSale $preSale): array
    {
        $customer = $preSale->relationLoaded('customer')
            ? $preSale->customer
            : $preSale->customer()->first();

        if (! $customer) {
            return $this->notEligible('customer_missing', 'Selecciona un cliente con datos fiscales antes de certificar FEL.');
        }

        $docType = strtoupper(trim((string) $customer->doc_type));
        $docNumber = strtoupper((string) preg_replace('/[\s-]+/', '', trim((string) $customer->doc_number)));
        $isFinalConsumer = $docType === 'CF' || $docNumber === 'CF' || (bool) $customer->is_final_consumer;

        if ($isFinalConsumer) {
            return (float) $preSale->total < 2500
                ? $this->eligible()
                : $this->notEligible('final_consumer_limit', 'Consumidor Final no puede certificarse por Q2,500.00 o más.');
        }

        if ($docType === 'CUI' || $docNumber === '' || ! preg_match('/^[A-Z0-9]+$/', $docNumber)) {
            return $this->notEligible('invalid_tax_id', 'El cliente no tiene un NIT fiscal válido para certificar FEL.');
        }

        if (! $customer->tax_lookup_verified_at || ! $customer->name_locked) {
            return $this->notEligible('nit_not_verified', 'El NIT debe validarse antes de certificar FEL.');
        }

        return $this->eligible();
    }

    public function persist(PreSale $preSale): array
    {
        $result = $this->evaluate($preSale);

        $preSale->forceFill([
            'fel_eligibility_status' => $result['status'],
            'fel_eligibility_reason_code' => $result['reason_code'],
            'fel_eligibility_reason' => $result['reason'],
            'fel_eligibility_checked_at' => now(),
        ])->save();

        return $result;
    }

    public function assertEligible(PreSale $preSale, string $field = 'fel'): array
    {
        $result = $this->persist($preSale);

        if (! $result['eligible']) {
            throw ValidationException::withMessages([$field => $result['reason']]);
        }

        return $result;
    }

    private function eligible(): array
    {
        return [
            'eligible' => true,
            'status' => 'eligible',
            'reason_code' => null,
            'reason' => null,
        ];
    }

    private function notEligible(string $reasonCode, string $reason): array
    {
        return [
            'eligible' => false,
            'status' => 'not_eligible',
            'reason_code' => $reasonCode,
            'reason' => $reason,
        ];
    }
}
