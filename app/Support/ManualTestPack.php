<?php

namespace App\Support;

use DateTimeImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use ZipArchive;

/**
 * Sample files for trying the whole workflow by hand (docs/manual-test/README.md):
 * a vehicle list, a cost list, a document folder ZIP with real text, and two photos plus a
 * scanned contract to upload. The numbers in the guide are checked by a test, so they stay true.
 *
 * Build with: php artisan dealer:manual-test-files
 */
class ManualTestPack
{
    /**
     * @return list<string> paths of the files written
     */
    public function build(string $directory): array
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}");
        }

        return [
            $this->vehicles($directory.'/1-Fahrzeuge.xlsx'),
            $this->costs($directory.'/2-Kosten.xlsx'),
            $this->documents($directory.'/3-Dokumente.zip'),
            $this->photo($directory.'/foto-vorne.png', 'VORNE', [200, 60, 60]),
            $this->photo($directory.'/foto-hinten.png', 'HINTEN', [60, 90, 200]),
            $this->scan($directory.'/scan-kaufvertrag.png'),
        ];
    }

    private function vehicles(string $path): string
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Info');
        $writer->addRow(Row::fromValues(['Bestandesliste Demo Garage Bern – Beispieldaten für den Import-Test']));
        $writer->addNewSheetAndMakeItCurrent()->setName('Fahrzeuge');
        $writer->addRow(Row::fromValues(['Nr.', 'Stammnummer', 'Fahrzeug', 'Status', 'EK Datum', 'EK CHF', 'Einkaufsart', 'Verkäufer', 'VK Datum', 'VK CHF', 'Käufer', 'KM Einkauf', 'KM Verkauf', 'Bemerkung']));

        $d = fn (string $date): DateTimeImmutable => new DateTimeImmutable($date);

        foreach ([
            // 2025 bought, 2026 sold: stays in the 2025 file (acceptance test 2)
            [201, '512.664.318', 'BMW X3 30i Blau', 'Verkauft', $d('2025-11-20'), 21500, 'Direkt', 'Reflex Automobiles Sàrl', $d('2026-01-15'), 26900, 'Anna Muster', 79310, 79500, ''],
            [202, '412.118.903', 'VW Golf 1.4 TSI Schwarz', 'Bestand', $d('2026-03-02'), 9800, 'Eintausch', 'Peter Keller', null, null, null, 120500, null, 'Service fällig'],
            [203, '305.221.774', 'Audi A4 Avant 2.0 TDI', 'Bestand', $d('2026-05-10'), 15400, 'Direkt', 'Reflex Automobiles Sàrl', null, null, null, 88000, null, ''],
            // sold three weeks ago: sold, not yet handed over
            [204, '507.112.840', 'Toyota Yaris Hybrid', 'Verkauft', $d('2026-09-01'), 12000, 'Direkt', 'Auto Bern AG', $d('2026-09-20'), 14900, 'Marco Rossi', 42000, null, ''],
            // bought back from the first buyer: same vehicle, new file
            [205, '512.664.318', 'BMW X3 30i Blau', 'Bestand', $d('2026-10-03'), 19800, 'Rücknahme', 'Anna Muster', null, null, null, 91000, null, 'Rückkauf von Frau Muster'],
            // problems the check must report
            [206, '12345', 'Lada Niva', 'Unklar', $d('2026-06-01'), null, 'ungeklärt', 'Reflex Automobiles Sàrl', null, null, null, 45000, null, 'Stammnummer falsch, Marke unbekannt, kein Preis'],
            [207, '998.877.665', 'Skoda Octavia Combi', 'Rückgabe', $d('2026-02-05'), 11000, 'Direkt', 'Peter Keller', null, null, null, 60000, null, 'Kauf rückgängig gemacht'],
        ] as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }

    private function costs(string $path): string
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Kosten');
        $writer->addRow(Row::fromValues(['Kosten-ID', 'Fahrzeug Nr.', 'Datum', 'Betrag CHF', 'Kategorie', 'Beschreibung', 'Lieferant']));

        foreach ([
            ['K0101', 201, '25.11.2025', 450.00, 'Transport', 'Abholung Zürich', 'Müller Transporte GmbH'],
            ['K0102', 202, '10.03.2026', 1180.40, 'Reparatur', 'Bremsen vorne', 'Garage Moser AG'],
            ['K0103', 202, '12.03.2026', 89.90, 'Aufbereitung', 'Innenreinigung', null],
            ['K0104', 203, '15.05.2026', 320.00, 'MFK', 'MFK Vorführung', null],
            ['K0105', 203, '20.05.2026', 60.00, null, 'Diverses', null],
            // problems the check must report
            ['K0106', 999, '01.06.2026', 100.00, 'Reparatur', 'Fahrzeug existiert nicht', null],
            ['K0107', 204, null, 100.00, 'Reparatur', 'Datum fehlt', null],
        ] as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }

    private function documents(string $path): string
    {
        @unlink($path);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $bmwContract = $this->pdf(['Kaufvertrag (Verkauf)', 'Verkäufer: Demo Garage Bern', 'Käufer: Anna Muster', 'Fahrzeug: BMW X3 30i Blau', 'Stammnummer: 512.664.318', 'Kaufpreis: CHF 26’900.00', 'Datum: 15.01.2026', 'Dokument D0104']);

        foreach ([
            '2025/512664318_BMW_X3/01_Ankauf/2025-11-20_512664318_Kaufvertrag_D0101.pdf' => ['Kaufvertrag (Ankauf)', 'Käufer: Demo Garage Bern', 'Verkäufer: Reflex Automobiles Sàrl', 'Fahrzeug: BMW X3 30i Blau', 'Stammnummer: 512.664.318', 'Kaufpreis: CHF 21’500.00', 'Datum: 20.11.2025', 'Dokument D0101'],
            '2025/512664318_BMW_X3/01_Ankauf/2025-11-20_512664318_Ausweis_D0102.pdf' => ['Ausweiskopie des Verkäufers', 'Reflex Automobiles Sàrl, Vertreter Jean Dupont', 'Dokument D0102'],
            '2025/512664318_BMW_X3/03_Werkstatt/2025-11-25_512664318_Rechnung_D0103.pdf' => ['Rechnung Müller Transporte GmbH', 'Abholung BMW X3 in Zürich', 'Stammnummer: 512.664.318', 'Betrag: CHF 450.00', 'Dokument D0103'],
            '2026/412118903_VW_Golf/01_Ankauf/2026-03-02_412118903_Kaufvertrag_D0106.pdf' => ['Kaufvertrag (Eintausch)', 'Fahrzeug: VW Golf 1.4 TSI', 'Stammnummer: 412.118.903', 'Verkäufer: Peter Keller', 'Wert: CHF 9’800.00', 'Dokument D0106'],
            '2026/412118903_VW_Golf/02_Fahrzeug/2026-03-02_412118903_Fahrzeugausweis_D0107.pdf' => ['Fahrzeugausweis', 'VW Golf 1.4 TSI', 'Stammnummer: 412.118.903', 'Dokument D0107'],
            '2026/305221774_Audi_A4/03_Werkstatt/2026-05-15_305221774_MFK_D0108.pdf' => ['MFK-Bericht', 'Audi A4 Avant 2.0 TDI', 'Stammnummer: 305.221.774', 'Ergebnis: bestanden', 'Dokument D0108'],
            // no vehicle with this Stammnummer, and none in the name: both end up in the inbox
            'Unsortiert/UNDATIERT_999999999_Rechnung_D0109.pdf' => ['Rechnung Garage unbekannt', 'Stammnummer: 999.999.999', 'Dokument D0109'],
            'Unsortiert/Notiz.pdf' => ['Notiz ohne Fahrzeug', 'Bitte zuordnen'],
        ] as $name => $lines) {
            $zip->addFromString($name, $this->pdf($lines));
        }

        $zip->addFromString('2025/512664318_BMW_X3/04_Verkauf/2026-01-15_512664318_Kaufvertrag_D0104.pdf', $bmwContract);
        // The same file under another name: an exact duplicate, skipped by the import
        $zip->addFromString('2025/512664318_BMW_X3/04_Verkauf/Kaufvertrag-Kopie.pdf', $bmwContract);
        $zip->addEmptyDir('__MACOSX');
        $zip->addFromString('.DS_Store', 'junk');
        $zip->close();

        return $path;
    }

    /**
     * @param  array{int, int, int}  $rgb
     */
    private function photo(string $path, string $label, array $rgb): string
    {
        $image = imagecreatetruecolor(640, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        imagefilledrectangle($image, 120, 160, 520, 300, imagecolorallocate($image, 240, 240, 240));
        imagefilledellipse($image, 200, 310, 80, 80, imagecolorallocate($image, 30, 30, 30));
        imagefilledellipse($image, 440, 310, 80, 80, imagecolorallocate($image, 30, 30, 30));
        imagestring($image, 5, 20, 20, "Testfoto {$label}", imagecolorallocate($image, 255, 255, 255));
        imagepng($image, $path);

        return $path;
    }

    /**
     * A "scanned" contract: text only exists as pixels, so reading it needs Tesseract.
     */
    private function scan(string $path): string
    {
        $lines = ['KAUFVERTRAG', 'Fahrzeug: Toyota Corolla', 'Stammnummer 228.461.775', 'Kaufpreis CHF 22900', 'Kunde: Luca Rossi, Lugano'];
        $small = imagecreatetruecolor(420, 40 + count($lines) * 30);
        imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));

        foreach ($lines as $i => $line) {
            imagestring($small, 5, 20, 20 + $i * 30, $line, imagecolorallocate($small, 0, 0, 0));
        }

        $big = imagescale($small, 1680, -1);
        imagepng($big, $path);

        return $path;
    }

    /**
     * A one-page PDF with real (selectable) text.
     *
     * @param  list<string>  $lines
     */
    private function pdf(array $lines): string
    {
        $encode = fn (string $text): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) iconv('UTF-8', 'windows-1252//TRANSLIT', str_replace('’', "'", $text)));

        $stream = "BT /F1 14 Tf 56 780 Td 22 TL\n";

        foreach ($lines as $line) {
            $stream .= '('.$encode($line).") Tj T*\n";
        }

        $stream .= 'ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
