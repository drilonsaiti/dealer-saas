<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Swiss conventions in all four UI languages: 79’310 km, 14.07.2026.
 */
final class SwissFormat
{
    public const DATE = 'd.m.Y';

    public const DATE_TIME = 'd.m.Y H:i';

    public static function number(?int $value): string
    {
        return $value === null ? '–' : number_format($value, 0, '.', Money::THOUSANDS_SEPARATOR);
    }

    public static function mileage(?int $km): string
    {
        return $km === null ? '–' : self::number($km).' km';
    }

    public static function date(?DateTimeInterface $date): string
    {
        return $date === null ? '–' : $date->format(self::DATE);
    }
}
