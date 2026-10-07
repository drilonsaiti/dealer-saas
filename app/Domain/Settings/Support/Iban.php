<?php

namespace App\Domain\Settings\Support;

/**
 * IBAN checks for Swiss QR bills.
 *
 * - Only Swiss (CH) and Liechtenstein (LI) IBANs are allowed on a QR bill.
 * - A QR-IBAN is a Swiss/LI IBAN whose institution ID (QR-IID, digits 5-9) is 30000-31999.
 *   It is issued by the bank and must be used with a QR reference.
 * - A normal IBAN is used with a SCOR (ISO 11649) reference or no reference.
 */
final class Iban
{
    public static function normalize(string $iban): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $iban));
    }

    public static function format(string $iban): string
    {
        return trim(chunk_split(self::normalize($iban), 4, ' '));
    }

    public static function isValid(string $iban): bool
    {
        $iban = self::normalize($iban);

        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        return self::mod97($numeric) === 1;
    }

    public static function isSwissOrLiechtenstein(string $iban): bool
    {
        $iban = self::normalize($iban);

        return self::isValid($iban)
            && in_array(substr($iban, 0, 2), ['CH', 'LI'], true)
            && strlen($iban) === 21;
    }

    public static function isQrIban(string $iban): bool
    {
        $iban = self::normalize($iban);

        if (! self::isSwissOrLiechtenstein($iban)) {
            return false;
        }

        $iid = (int) substr($iban, 4, 5);

        return $iid >= 30000 && $iid <= 31999;
    }

    private static function mod97(string $numeric): int
    {
        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder;
    }
}
