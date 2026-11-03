<?php

namespace App\Support;

use App\Models\Product;
use App\Models\TenantSetting;
use Illuminate\Validation\ValidationException;

class ManualPricePolicy
{
    public const MODE_COST_MARKUP = 'cost_markup';
    public const MODE_PRICE_DISCOUNT = 'price_discount';
    public const MODE_NONE = 'none';

    public const INVALID_PRICE_MESSAGE = 'Este precio no está permitido.';
    public const MISSING_COST_MESSAGE = 'Este producto no tiene costo configurado.';
    public const MISSING_PRICE_MESSAGE = 'Este producto no tiene precio principal configurado.';
    public const MAX_PERCENT_DISCOUNT = 25.0;

    public static function mode(?TenantSetting $settings): string
    {
        $mode = $settings?->manual_price_percentage_mode ?: self::MODE_COST_MARKUP;

        return in_array($mode, [self::MODE_COST_MARKUP, self::MODE_PRICE_DISCOUNT, self::MODE_NONE], true)
            ? $mode
            : self::MODE_COST_MARKUP;
    }

    public static function minMarkupPercent(?TenantSetting $settings): float
    {
        $newValue = (float) ($settings?->manual_price_min_markup_percent ?? 0);
        $legacyValue = (float) ($settings?->manual_price_min_margin_percent ?? 0);
        $value = $newValue > 0 ? $newValue : $legacyValue;

        return max(0.0, round((float) ($value ?? 0), 2));
    }

    public static function maxDiscountPercent(?TenantSetting $settings): float
    {
        return min(100.0, max(0.0, round((float) ($settings?->manual_price_max_discount_percent ?? 0), 2)));
    }

    public static function percentageSteps(?TenantSetting $settings): array
    {
        return [2.0, 5.0, 10.0, 15.0, 20.0, self::MAX_PERCENT_DISCOUNT];
    }

    public static function calculateFromPercentage(?TenantSetting $settings, Product $product, float $percentage, ?float $mainPrice = null): float
    {
        if ($percentage < 0 || $percentage >= 100) {
            throw ValidationException::withMessages(['items' => self::INVALID_PRICE_MESSAGE]);
        }

        $basePrice = $mainPrice ?? (float) $product->sale_price;

        if ($basePrice <= 0) {
            throw ValidationException::withMessages(['items' => self::MISSING_PRICE_MESSAGE]);
        }

        if (self::mode($settings) === self::MODE_PRICE_DISCOUNT && $percentage > self::maxDiscountPercent($settings)) {
            throw ValidationException::withMessages(['items' => 'El descuento máximo permitido es '.self::formatPercent(self::maxDiscountPercent($settings)).'%.']);
        }

        $price = round($basePrice * (1 - ($percentage / 100)), 2);
        self::validateUnitPrice($settings, $product, $price, $mainPrice);

        return $price;
    }

    public static function minimumAllowedPrice(?TenantSetting $settings, Product $product, ?float $mainPrice = null): float
    {
        return match (self::mode($settings)) {
            self::MODE_PRICE_DISCOUNT => self::minimumPriceDiscount($settings, $product, $mainPrice),
            default => self::minimumCostMarkup($settings, $product),
        };
    }

    public static function validateUnitPrice(?TenantSetting $settings, Product $product, float $unitPrice, ?float $mainPrice = null): void
    {
        if ($unitPrice <= 0) {
            throw ValidationException::withMessages(['items' => self::INVALID_PRICE_MESSAGE]);
        }

        $minimum = self::minimumAllowedPrice($settings, $product, $mainPrice);

        if ($minimum > 0 && $unitPrice < $minimum) {
            throw ValidationException::withMessages([
                'items' => self::INVALID_PRICE_MESSAGE,
            ]);
        }
    }

    private static function minimumCostMarkup(?TenantSetting $settings, Product $product): float
    {
        $cost = (float) ($product->cost_price ?? 0);

        if ($cost <= 0) {
            throw ValidationException::withMessages(['items' => self::MISSING_COST_MESSAGE]);
        }

        return round($cost * (1 + (self::minMarkupPercent($settings) / 100)), 2);
    }

    private static function minimumPriceDiscount(?TenantSetting $settings, Product $product, ?float $mainPrice): float
    {
        $basePrice = $mainPrice ?? (float) $product->sale_price;

        if ($basePrice <= 0) {
            throw ValidationException::withMessages(['items' => self::MISSING_PRICE_MESSAGE]);
        }

        return round($basePrice * (1 - (self::maxDiscountPercent($settings) / 100)), 2);
    }

    private static function formatPercent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
