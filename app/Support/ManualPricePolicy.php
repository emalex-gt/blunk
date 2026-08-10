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
        $mode = self::mode($settings);

        if ($mode === self::MODE_NONE) {
            return [];
        }

        if ($mode === self::MODE_PRICE_DISCOUNT) {
            $max = self::maxDiscountPercent($settings);

            if ($max <= 0) {
                return [];
            }

            return collect([2, 5, 10, 15, 20, 25, 30, 50])
                ->filter(fn (int $step) => $step <= $max)
                ->when(! in_array((int) $max, [2, 5, 10, 15, 20, 25, 30, 50], true), fn ($steps) => $steps->push($max))
                ->unique()
                ->sort()
                ->values()
                ->map(fn ($step) => (float) $step)
                ->all();
        }

        $min = self::minMarkupPercent($settings);

        return collect([$min, 5, 10, 15, 20, 25, 30, 50])
            ->filter(fn ($step) => (float) $step >= $min)
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($step) => (float) $step)
            ->all();
    }

    public static function calculateFromPercentage(?TenantSetting $settings, Product $product, float $percentage, ?float $mainPrice = null): float
    {
        $percentage = max(0.0, $percentage);

        if (self::mode($settings) === self::MODE_PRICE_DISCOUNT) {
            $basePrice = $mainPrice ?? (float) $product->sale_price;

            if ($basePrice <= 0) {
                throw ValidationException::withMessages(['items' => self::MISSING_PRICE_MESSAGE]);
            }

            if ($percentage > self::maxDiscountPercent($settings)) {
                throw ValidationException::withMessages(['items' => 'El descuento máximo permitido es '.self::formatPercent(self::maxDiscountPercent($settings)).'%.']);
            }

            return round($basePrice * (1 - ($percentage / 100)), 2);
        }

        if (self::mode($settings) === self::MODE_NONE) {
            throw ValidationException::withMessages(['items' => self::INVALID_PRICE_MESSAGE]);
        }

        $cost = (float) ($product->cost_price ?? 0);

        if ($cost <= 0) {
            throw ValidationException::withMessages(['items' => self::MISSING_COST_MESSAGE]);
        }

        if ($percentage < self::minMarkupPercent($settings)) {
            throw ValidationException::withMessages(['items' => 'El porcentaje mínimo sobre costo es '.self::formatPercent(self::minMarkupPercent($settings)).'%.']);
        }

        return round($cost * (1 + ($percentage / 100)), 2);
    }

    public static function minimumAllowedPrice(?TenantSetting $settings, Product $product, ?float $mainPrice = null): float
    {
        return match (self::mode($settings)) {
            self::MODE_PRICE_DISCOUNT => self::minimumPriceDiscount($settings, $product, $mainPrice),
            self::MODE_NONE => 0.0,
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
