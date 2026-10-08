<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is stored as integer Rappen (bigint), never as floats.
 * Swiss display: CHF 21’000.00 (apostrophe thousands separator, point decimals) in every UI language.
 */
final class Money
{
    public const THOUSANDS_SEPARATOR = '’';

    public static function format(?int $rappen, bool $withCurrency = true, string $currency = 'CHF'): string
    {
        if ($rappen === null) {
            return '–';
        }

        $sign = $rappen < 0 ? '-' : '';
        $absolute = abs($rappen);
        $francs = intdiv($absolute, 100);
        $cents = $absolute % 100;

        $amount = $sign.number_format($francs, 0, '.', self::THOUSANDS_SEPARATOR).'.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT);

        return $withCurrency ? "{$currency} {$amount}" : $amount;
    }

    /**
     * Amount for an input field: 21’000.00 → shown and edited without the currency.
     */
    public static function toInput(?int $rappen): ?string
    {
        return $rappen === null ? null : self::format($rappen, withCurrency: false);
    }

    /**
     * Parses what people type: 21000, 21'000, 21’000.–, 21 000.50, 21000,5, CHF 21’000.00.
     */
    public static function parse(string|int|float|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        $text = trim(str_ireplace('CHF', '', (string) $value));
        $text = str_replace(["'", '’', '‘', ' ', "\u{00A0}", "\u{202F}"], '', $text);
        $text = preg_replace('/[.,][-–—]+$/u', '', $text) ?? $text;

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-+');

        if (preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new InvalidArgumentException("Not an amount: {$value}");
        }

        $rappen = ((int) $matches[1]) * 100 + (int) str_pad($matches[2] ?? '0', 2, '0');

        return $negative ? -$rappen : $rappen;
    }

    public static function isParsable(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value) && $value !== null) {
            return false;
        }

        try {
            self::parse($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
