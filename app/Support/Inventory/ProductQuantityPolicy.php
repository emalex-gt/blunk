<?php

namespace App\Support\Inventory;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Canonicalizes product quantities without converting them through float.
 * Monetary calculations intentionally remain outside this policy.
 */
class ProductQuantityPolicy
{
    public static function canonical(mixed $quantity): string
    {
        return self::absolute(new Product(['allow_decimal_quantity' => true]), $quantity);
    }

    public static function positive(Product $product, mixed $quantity): string
    {
        return self::validate($product, $quantity, false);
    }

    public static function absolute(Product $product, mixed $quantity): string
    {
        return self::validate($product, $quantity, true);
    }

    private static function validate(Product $product, mixed $quantity, bool $allowZero): string
    {
        if (! is_string($quantity) && ! is_int($quantity) && ! is_float($quantity)) {
            self::fail('La cantidad debe ser un número válido.');
        }

        $value = str_replace(',', '.', trim((string) $quantity));
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            self::fail('La cantidad debe ser un número positivo de hasta cuatro decimales.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($fraction, '0');
        $normalized = $fraction === '' ? $whole : "{$whole}.{$fraction}";

        if ($normalized === '0' && ! $allowZero) {
            self::fail('La cantidad debe ser mayor que cero.');
        }

        if (! (bool) $product->allow_decimal_quantity && $fraction !== '') {
            self::fail('Este producto solo permite cantidades enteras.');
        }

        return $normalized;
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['quantity' => $message]);
    }
}
