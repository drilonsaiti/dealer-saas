<?php

namespace App\Domain\Parties\Support;

/**
 * Swiss-first E.164 normalisation for duplicate checks: "079 123 45 67", "0041 79 123 45 67",
 * "+41 (0)79 123 45 67" and "+41791234567" all become +41791234567. Foreign numbers keep their own country code.
 */
final class PhoneNumber
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // "+41 (0)79 ..." – the (0) is only dialled inside Switzerland.
        $digits = preg_replace('/[^\d+]/', '', str_replace('(0)', '', $value)) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '+41'.substr($digits, 1);
        }

        $digits = '+'.ltrim($digits, '+');

        return strlen($digits) >= 8 ? $digits : null;
    }
}
