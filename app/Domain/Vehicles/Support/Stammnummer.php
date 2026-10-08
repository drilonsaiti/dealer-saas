<?php

namespace App\Domain\Vehicles\Support;

/**
 * The Swiss Stammnummer (registration master number): stored as 9 digits, shown as 683.737.537.
 * Imports and users write it in many ways ("683 737 537", "683737537", "000.653.461.306");
 * normalize() turns all of them into the stored form.
 */
final class Stammnummer
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        // Some exports prefix the number with zero groups ("000.653.461.306").
        if (strlen($digits) > 9 && ltrim(substr($digits, 0, -9), '0') === '') {
            $digits = substr($digits, -9);
        }

        return $digits;
    }

    public static function isValid(?string $value): bool
    {
        $normalized = self::normalize($value);

        return $normalized !== null && preg_match('/^\d{9}$/', $normalized) === 1;
    }

    public static function format(?string $value): ?string
    {
        $normalized = self::normalize($value);

        if ($normalized === null || strlen($normalized) !== 9) {
            return $value;
        }

        return implode('.', str_split($normalized, 3));
    }
}
