<?php

namespace App\Services;

class CurrencyConverter
{
    /**
     * Static rates, NOT live — good enough for a portfolio project, wrong
     * for anything real. Production version would call a live FX API and
     * cache the rate for a day, not hardcode it. Update these periodically
     * by hand for now.
     */
    private const RATES_TO_MYR = [
        'MYR' => 1.0,
        'USD' => 4.70,
        'SGD' => 3.48,
        'GBP' => 5.95,
        'EUR' => 5.10,
    ];

    public function toMyr(float $amount, string $currency): float
    {
        $rate = self::RATES_TO_MYR[$currency] ?? 1.0; // unknown currency: assume MYR rather than silently drop the amount

        return round($amount * $rate, 2);
    }
}