<?php

namespace App\Support;

class Currency
{
    public static function formatMoney(float|int|string|null $amount, ?string $country): string
    {
        $config = config('currency.'.($country ?: 'GT'), config('currency.GT'));
        $formattedAmount = number_format((float) ($amount ?? 0), 2, '.', ',');
        $symbol = $config['symbol'] ?? 'Q';

        if (($config['position'] ?? 'before') === 'after') {
            return "{$formattedAmount} {$symbol}";
        }

        return "{$symbol} {$formattedAmount}";
    }

    public static function forCountry(?string $country): array
    {
        return config('currency.'.($country ?: 'GT'), config('currency.GT'));
    }
}
