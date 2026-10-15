<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Services\Fel\FelIdentityLookupException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Authoritative persistence policy for Guatemala fiscal identities.
 *
 * CF is manually maintained; NIT and CUI are accepted only from a verified
 * lookup for the exact submitted type and canonical document number.
 */
class GuatemalaCustomerIdentityPolicy
{
    /**
     * @return array{doc_type: string, doc_number: string, name: ?string, tax_lookup_verified_at: mixed, tax_lookup_payload: ?array}
     */
    public function resolve(Business $business, mixed $submittedType, mixed $submittedNumber, ?Customer $existing = null): array
    {
        if (strtoupper((string) $business->country) !== 'GT') {
            throw ValidationException::withMessages([
                'doc_type' => 'La validación de identidad fiscal Guatemala está disponible solo para negocios de Guatemala.',
            ]);
        }

        $docType = CustomerIdentity::normalizeGuatemalaDocType($submittedType);

        if ($docType === null) {
            throw ValidationException::withMessages([
                'doc_type' => 'El tipo de documento debe ser NIT, CUI o CF.',
            ]);
        }

        $docNumber = CustomerIdentity::normalizeDocument($docType, $submittedNumber) ?? '';

        if ($docType === CustomerIdentity::CF) {
            return [
                'doc_type' => CustomerIdentity::CF,
                'doc_number' => CustomerIdentity::CF,
                'name' => null,
                'tax_lookup_verified_at' => null,
                'tax_lookup_payload' => null,
            ];
        }

        if ($docType === CustomerIdentity::CUI && ! CustomerIdentity::isValidCui($docNumber)) {
            throw ValidationException::withMessages([
                'doc_number' => GuatemalaCuiCustomerResolver::INVALID_CUI_MESSAGE,
            ]);
        }

        if ($docType === CustomerIdentity::NIT
            && ($docNumber === '' || $docNumber === CustomerIdentity::CF || ! preg_match('/^[A-Z0-9]+$/', $docNumber))) {
            throw ValidationException::withMessages([
                'doc_number' => GuatemalaNitCustomerResolver::INVALID_NIT_MESSAGE,
            ]);
        }

        $existing ??= $this->existingIdentity($business, $docType, $docNumber);

        if ($this->isExactVerifiedIdentity($business, $existing, $docType, $docNumber)) {
            return [
                'doc_type' => $docType,
                'doc_number' => $docNumber,
                'name' => $existing->name,
                'tax_lookup_verified_at' => $existing->tax_lookup_verified_at,
                'tax_lookup_payload' => $existing->tax_lookup_payload,
            ];
        }

        try {
            $lookup = match ($docType) {
                CustomerIdentity::NIT => GuatemalaNitCustomerResolver::lookupTaxData($business, $docNumber),
                CustomerIdentity::CUI => GuatemalaCuiCustomerResolver::lookupTaxData($business, $docNumber),
            };
        } catch (FelIdentityLookupException $exception) {
            throw ValidationException::withMessages([
                'doc_number' => $exception->getMessage(),
            ]);
        } catch (ValidationException $exception) {
            $message = $exception->errors()['doc_number'][0]
                ?? $exception->errors()['customer.doc_number'][0]
                ?? $exception->errors()['nit'][0]
                ?? $exception->errors()['cui'][0]
                ?? 'No se pudo validar la identidad fiscal.';

            throw ValidationException::withMessages(['doc_number' => $message]);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'doc_number' => $docType === CustomerIdentity::CUI
                    ? 'No se pudo validar el CUI. Inténtalo nuevamente.'
                    : GuatemalaNitCustomerResolver::LOOKUP_ERROR_MESSAGE,
            ]);
        }

        $verifiedNumber = $docType === CustomerIdentity::NIT
            ? GuatemalaNitCustomerResolver::normalize($lookup['nit'] ?? null)
            : GuatemalaCuiCustomerResolver::normalize($lookup['cui'] ?? null);

        if ($verifiedNumber !== $docNumber || blank($lookup['name'] ?? null)) {
            throw ValidationException::withMessages([
                'doc_number' => 'La validación fiscal no corresponde al documento ingresado.',
            ]);
        }

        return [
            'doc_type' => $docType,
            'doc_number' => $verifiedNumber,
            'name' => trim((string) $lookup['name']),
            'tax_lookup_verified_at' => now(),
            'tax_lookup_payload' => is_array($lookup['raw'] ?? null) ? $lookup['raw'] : null,
        ];
    }

    private function isExactVerifiedIdentity(Business $business, ?Customer $customer, string $docType, string $docNumber): bool
    {
        return $customer !== null
            && (int) $customer->business_id === (int) $business->id
            && $customer->merged_into_customer_id === null
            && strtoupper((string) $customer->country) === 'GT'
            && CustomerIdentity::normalizeGuatemalaDocType($customer->doc_type) === $docType
            && CustomerIdentity::normalizeDocument($docType, $customer->doc_number) === $docNumber
            && $customer->tax_lookup_verified_at !== null
            && (bool) $customer->name_locked;
    }

    private function existingIdentity(Business $business, string $docType, string $docNumber): ?Customer
    {
        return Customer::query()
            ->where('business_id', $business->id)
            ->whereNull('merged_into_customer_id')
            ->where('normalized_tax_id', $docNumber)
            ->where(function ($query) use ($docType): void {
                $query->where('doc_type', $docType);

                if ($docType === CustomerIdentity::NIT) {
                    $query->orWhereNull('doc_type');
                }
            })
            ->first();
    }
}
