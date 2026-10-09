<?php

namespace App\Domain\Vat\Support;

/**
 * Prices in the vehicle trade include VAT. The VAT contained in a gross amount is
 * gross × rate / (100 + rate), rounded to the Rappen.
 */
final class VatMath
{
    public static function includedVat(int $grossRp, float $percent): int
    {
        if ($percent <= 0.0) {
            return 0;
        }

        return (int) round($grossRp * $percent / (100 + $percent));
    }
}
