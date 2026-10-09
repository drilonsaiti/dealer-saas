<?php

namespace App\Domain\Invoicing\Support;

use App\Domain\Vat\Models\VatCode;
use App\Domain\Vat\Support\VatMath;
use DateTimeInterface;

/**
 * Gross line total, the VAT it contains and the net amount, with the rate valid on the date.
 */
final class LineAmounts
{
    /**
     * @return array{total_rp: int, vat_rp: int, net_rp: int, vat_rate: float}
     */
    public static function for(?VatCode $code, int $unitPriceRp, float $qty, DateTimeInterface $on): array
    {
        $total = (int) round($unitPriceRp * $qty);
        $rate = $code?->percentOn($on) ?? 0.0;
        $vat = VatMath::includedVat($total, $rate);

        return ['total_rp' => $total, 'vat_rp' => $vat, 'net_rp' => $total - $vat, 'vat_rate' => $rate];
    }
}
