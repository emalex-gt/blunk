<?php

namespace App\Support;

/**
 * Shared POS checkout payment methods. Keep the backend sale validator and
 * Fuel bootstrap on the same list. Adding a new method here ALSO requires
 * verifying that the POS checkout contract accepts it before enabling it.
 */
final class CheckoutPaymentMethods
{
    public static function forCountry(string $country): array
    {
        $methods = [
            ['id' => 'cash', 'label' => 'Efectivo'],
            ['id' => 'card', 'label' => 'Tarjeta'],
            ['id' => 'transfer', 'label' => 'Transferencia'],
            ['id' => 'deposit', 'label' => 'Depósito'],
            ['id' => 'check', 'label' => 'Cheque'],
        ];

        if ($country === 'AR') {
            $methods[] = ['id' => 'mercadopago', 'label' => 'MercadoPago'];
        }

        // GT includes deposit: verified against the local checkout validator
        // in the Blunk 7 integration candidate on 2026-11-25.
        return $methods;
    }

    public static function codesForCountry(string $country): array
    {
        return array_column(self::forCountry($country), 'id');
    }
}
