<?php

namespace App\Domain\Accounting\Support;

/**
 * The journal as CSV for Swiss accounting software (Banana, Bexio, Abacus, Excel): UTF-8 with
 * BOM, semicolons, date dd.mm.yyyy, amounts with a dot and two decimals, one booking per row.
 */
final class JournalCsv
{
    /**
     * @param  list<JournalEntry>  $entries
     */
    public static function render(array $entries, string $exportNumber, string $locale = 'de'): string
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);

        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, [
            __('Date', [], $locale), __('Voucher', [], $locale), __('Debit', [], $locale), __('Credit', [], $locale),
            __('Amount', [], $locale), __('VAT rate', [], $locale), __('Text', [], $locale), __('Vehicle file', [], $locale),
            __('Source', [], $locale), __('Source ID', [], $locale), __('Export', [], $locale),
        ], ';', '"', '');

        foreach ($entries as $entry) {
            fputcsv($handle, [
                date('d.m.Y', (int) strtotime($entry->date)),
                $entry->voucher,
                $entry->debit,
                $entry->credit,
                number_format($entry->amountRp / 100, 2, '.', ''),
                $entry->vatRate,
                self::safe($entry->text),
                $entry->fileNumber,
                $entry->sourceType,
                $entry->sourceId,
                $exportNumber,
            ], ';', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Texts starting with = + - @ would run as formulas when the file is opened in Excel.
     */
    private static function safe(string $text): string
    {
        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }
}
