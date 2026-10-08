<?php

namespace App\Domain\Import\Support;

use App\Support\Money;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Turns what dealers keep in Excel into clean values: Swiss dates (14.07.2026, 14.7.26,
 * 2026-07-14, Excel date cells), amounts (21’000.–), integers with apostrophes (79’310).
 */
final class Normalize
{
    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim(is_scalar($value) ? (string) $value : '');

        return $text === '' ? null : $text;
    }

    public static function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        if (is_numeric($text) && (float) $text > 20000 && (float) $text < 80000) {
            // Excel serial date stored as a number.
            return Carbon::create(1899, 12, 30)->addDays((int) $text)->toDateString();
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/', $text, $m) === 1) {
            $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

            if (! checkdate((int) $m[2], (int) $m[1], $year)) {
                throw new InvalidArgumentException("Not a date: {$text}");
            }

            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1) {
            return substr($text, 0, 10);
        }

        throw new InvalidArgumentException("Not a date: {$text}");
    }

    public static function money(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        return Money::parse(is_scalar($value) ? (string) $value : '');
    }

    public static function integer(mixed $value): ?int
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        $digits = str_replace(["'", '’', ' ', "\u{00A0}"], '', $text);
        $digits = preg_replace('/\s*(km|kw|cm3|cm³|kg)$/i', '', $digits) ?? $digits;

        if (! is_numeric($digits)) {
            throw new InvalidArgumentException("Not a number: {$text}");
        }

        return (int) round((float) $digits);
    }

    /**
     * Compare names loosely: case, accents, punctuation and legal-form spacing.
     */
    public static function nameKey(string $name): string
    {
        $ascii = mb_strtolower(trim($name));
        $ascii = strtr($ascii, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'ô' => 'o', 'î' => 'i']);

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? $ascii;
    }

    /**
     * Codes and labels (in every UI language, lower case) of a labelled enum → code, so a list
     * exported in any language imports back.
     *
     * @param  class-string<\BackedEnum&HasLabel>  $enum
     * @return array<string, string>
     */
    public static function enumLabels(string $enum): array
    {
        $translator = app('translator');
        $current = $translator->getLocale();
        $labels = [];

        try {
            $translator->setLocale('en');

            foreach ($enum::cases() as $case) {
                $labels[(string) $case->value] = (string) $case->value;
                $key = (string) $case->getLabel();

                foreach ((array) config('dealer.locales') as $locale) {
                    $labels[mb_strtolower((string) $translator->get($key, [], (string) $locale))] = (string) $case->value;
                }
            }
        } finally {
            $translator->setLocale($current);
        }

        return $labels;
    }
}
