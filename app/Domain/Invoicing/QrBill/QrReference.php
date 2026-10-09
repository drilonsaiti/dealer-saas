<?php

namespace App\Domain\Invoicing\QrBill;

/**
 * Payment references of the Swiss QR bill: the 27-digit QR reference (with a QR-IBAN) and
 * the ISO 11649 creditor reference "RF.." (with a normal IBAN). Both let incoming payments
 * be matched automatically.
 */
final class QrReference
{
    /**
     * 26 digits from $digits (left-padded) plus the modulo-10 recursive check digit.
     */
    public static function qrr(string $digits): string
    {
        $base = str_pad(substr(preg_replace('/\D/', '', $digits) ?? '', -26), 26, '0', STR_PAD_LEFT);

        return $base.self::mod10($base);
    }

    public static function isValidQrr(string $reference): bool
    {
        return preg_match('/^\d{27}$/', $reference) === 1 && self::mod10(substr($reference, 0, 26)) === (int) $reference[26];
    }

    /**
     * RF + two check digits + the reference (letters and digits, up to 21).
     */
    public static function scor(string $reference): string
    {
        $reference = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '');
        $reference = substr($reference, -21);
        $check = 98 - self::mod97(self::toNumeric($reference.'RF00'));

        return 'RF'.str_pad((string) $check, 2, '0', STR_PAD_LEFT).$reference;
    }

    public static function isValidScor(string $reference): bool
    {
        $reference = strtoupper(str_replace(' ', '', $reference));

        return preg_match('/^RF\d{2}[A-Z0-9]{1,21}$/', $reference) === 1
            && self::mod97(self::toNumeric(substr($reference, 4).substr($reference, 0, 4))) === 1;
    }

    /**
     * Grouped for print: QR reference in blocks of five from the right, RF.. in blocks of four.
     */
    public static function format(string $reference): string
    {
        if (str_starts_with($reference, 'RF')) {
            return trim(chunk_split($reference, 4, ' '));
        }

        $first = strlen($reference) % 5;

        return trim(substr($reference, 0, $first).' '.chunk_split(substr($reference, $first), 5, ' '));
    }

    private static function mod10(string $digits): int
    {
        $table = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5];
        $carry = 0;

        foreach (str_split($digits) as $digit) {
            $carry = $table[($carry + (int) $digit) % 10];
        }

        return (10 - $carry) % 10;
    }

    private static function toNumeric(string $value): string
    {
        return implode('', array_map(fn (string $c): string => ctype_alpha($c) ? (string) (ord($c) - 55) : $c, str_split($value)));
    }

    private static function mod97(string $numeric): int
    {
        $rest = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $rest = (int) ($rest.$chunk) % 97;
        }

        return $rest;
    }
}
