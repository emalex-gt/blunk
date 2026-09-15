<?php

namespace App\Services\Routes;

use Illuminate\Validation\ValidationException;

class RoutePreSalePaymentMethodPolicy
{
    /** @return array{active:bool,allowed_methods:array<int,string>,primary_method:?string} */
    public function forBranch(int $businessId, int $branchId): array
    {
        $setting = app(RouteBranchCollectionSettingsService::class)->forBranch($businessId, $branchId);

        if (! $setting) {
            return [
                'active' => false,
                'allowed_methods' => [],
                'primary_method' => null,
            ];
        }

        return [
            'active' => true,
            'allowed_methods' => $setting->allowed_payment_methods,
            'primary_method' => $setting->primary_payment_method,
        ];
    }

    public function resolveForSave(
        int $businessId,
        int $branchId,
        ?string $requestedMethod,
        bool $methodProvided,
        ?string $existingMethod,
        bool $creating,
    ): ?string {
        $policy = $this->forBranch($businessId, $branchId);

        if (! $policy['active']) {
            return $methodProvided ? $requestedMethod : $existingMethod;
        }

        if (! $methodProvided) {
            if ($creating) {
                return $policy['primary_method'];
            }

            if (is_string($existingMethod) && in_array($existingMethod, $policy['allowed_methods'], true)) {
                return $existingMethod;
            }

            throw ValidationException::withMessages([
                'payment_method' => 'Selecciona una forma de pago permitida para esta sucursal.',
            ]);
        }

        if (! is_string($requestedMethod) || ! in_array($requestedMethod, $policy['allowed_methods'], true)) {
            throw ValidationException::withMessages([
                'payment_method' => 'La forma de pago no está permitida para esta sucursal.',
            ]);
        }

        return $requestedMethod;
    }
}
