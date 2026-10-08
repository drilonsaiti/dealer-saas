<?php

namespace App\Domain\Parties\Support;

/**
 * Proposes a customer's correspondence language from the Swiss postcode.
 * Only a proposal: the user can always change it on the customer.
 */
final class SwissLanguageRegion
{
    public static function localeForPostcode(?string $zip, string $fallback = 'de'): string
    {
        if ($zip === null || preg_match('/^\d{4}$/', trim($zip)) !== 1) {
            return $fallback;
        }

        $code = (int) trim($zip);

        return match (true) {
            $code >= 2500 && $code <= 2565 => 'de', // Biel/Bienne region, German-speaking majority
            $code >= 1000 && $code <= 2999 => 'fr', // Romandie and Jura
            $code >= 6500 && $code <= 6999 => 'it', // Ticino and Italian-speaking Graubünden
            $code >= 7741 && $code <= 7748 => 'it', // Valposchiavo
            default => 'de',
        };
    }
}
