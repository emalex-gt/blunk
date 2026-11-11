<?php

namespace App\Support\Inventory;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Exact, product-aware quantities. Monetary rounding intentionally belongs
 * to the caller; quantities are never rounded or truncated here.
 */
class ProductQuantityPolicy
{
    public const SCALE = 4;

    public static function canonical(mixed $quantity): string
    {
        return self::normalize($quantity, true, false);
    }

    /**
     * Canonical representation for persisted stock balances. A balance can be
     * negative only for tenants that explicitly allow negative stock; inputs
     * for movements still go through positive()/absolute().
     */
    public static function signed(mixed $quantity): string
    {
        return self::normalize($quantity, true, true);
    }

    public static function canonicalForIdempotency(mixed $quantity): int|string
    {
        $canonical = self::canonical($quantity);

        return str_contains($canonical, '.') ? $canonical : (int) $canonical;
    }

    public static function positive(Product $product, mixed $quantity): string
    {
        $value = self::normalize($quantity, false, false);
        self::assertAllowedForProduct($product, $value);

        return $value;
    }

    public static function absolute(Product $product, mixed $quantity): string
    {
        $value = self::normalize($quantity, true, false);
        self::assertAllowedForProduct($product, $value);

        return $value;
    }

    public static function add(mixed $left, mixed $right): string
    {
        return self::trim(bcadd(self::signed($left), self::signed($right), self::SCALE));
    }

    public static function subtract(mixed $left, mixed $right): string
    {
        return self::trim(bcsub(self::signed($left), self::signed($right), self::SCALE));
    }

    public static function compare(mixed $left, mixed $right): int
    {
        return bccomp(self::signed($left), self::signed($right), self::SCALE);
    }

    public static function minimum(mixed $left, mixed $right): string
    {
        return self::compare($left, $right) <= 0 ? self::canonical($left) : self::canonical($right);
    }

    public static function maximum(mixed $left, mixed $right): string
    {
        return self::compare($left, $right) >= 0 ? self::canonical($left) : self::canonical($right);
    }

    public static function isAvailable(mixed $available, mixed $requested): bool
    {
        return self::compare($available, $requested) >= 0;
    }

    private static function assertAllowedForProduct(Product $product, string $quantity): void
    {
        if (! (bool) $product->allow_decimal_quantity && str_contains($quantity, '.')) {
            self::fail('Este producto solo permite cantidades enteras.');
        }
    }

    private static function normalize(mixed $quantity, bool $allowZero, bool $allowNegative): string
    {
        // Legacy commercial flows are still integer-only in D2.1 and some
        // pass those integers as floats. Accept only exact integer floats so
        // they remain compatible without accepting a lossy decimal float.
        if (is_float($quantity) && floor($quantity) === $quantity) {
            $quantity = sprintf('%.0f', $quantity);
        }

        if (! is_string($quantity) && ! is_int($quantity)) {
            self::fail('La cantidad debe ser un número válido.');
        }

        $value = str_replace(',', '.', trim((string) $quantity));
        if (! preg_match('/^'.($allowNegative ? '-?' : '').'\d+(?:\.\d{1,'.self::SCALE.'})?$/', $value)) {
            self::fail('La cantidad debe ser un número positivo de hasta cuatro decimales.');
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($fraction, '0');
        $normalized = $fraction === '' ? $whole : "{$whole}.{$fraction}";
        $normalized = $negative && $normalized !== '0' ? "-{$normalized}" : $normalized;

        if ($normalized === '0' && ! $allowZero) {
            self::fail('La cantidad debe ser mayor que cero.');
        }

        return $normalized;
    }

    private static function trim(string $quantity): string
    {
        $quantity = rtrim(rtrim($quantity, '0'), '.');

        return $quantity === '' || $quantity === '-0' ? '0' : $quantity;
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['quantity' => $message]);
    }
}
