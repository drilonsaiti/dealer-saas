<?php

namespace App\Domain\Vehicles\Support;

/**
 * Vehicle identification number (ISO 3779): 17 characters, letters I, O and Q never occur.
 */
final class Vin
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $vin = strtoupper(preg_replace('/[\s\-.]/', '', $value) ?? '');

        return $vin === '' ? null : $vin;
    }

    public static function isValid(?string $value): bool
    {
        $vin = self::normalize($value);

        return $vin !== null && preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin) === 1;
    }
}
