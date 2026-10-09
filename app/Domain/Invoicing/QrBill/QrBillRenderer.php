<?php

namespace App\Domain\Invoicing\QrBill;

use App\Domain\Settings\Support\Iban;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * The payment part of a Swiss QR bill (receipt 62 mm + payment part 148 mm, 105 mm high)
 * as HTML with an inline SVG QR code, for the PDF renderer. Texts and sizes follow the
 * Swiss Payment Standards style guide; the labels are fixed by the standard per language.
 */
class QrBillRenderer
{
    private const LABELS = [
        'de' => ['receipt' => 'Empfangsschein', 'payment' => 'Zahlteil', 'account' => 'Konto / Zahlbar an', 'reference' => 'Referenz', 'info' => 'Zusätzliche Informationen', 'payable_by' => 'Zahlbar durch', 'payable_by_blank' => 'Zahlbar durch (Name/Adresse)', 'currency' => 'Währung', 'amount' => 'Betrag', 'acceptance' => 'Annahmestelle', 'separate' => 'Vor der Einzahlung abzutrennen'],
        'fr' => ['receipt' => 'Récépissé', 'payment' => 'Section paiement', 'account' => 'Compte / Payable à', 'reference' => 'Référence', 'info' => 'Informations supplémentaires', 'payable_by' => 'Payable par', 'payable_by_blank' => 'Payable par (nom/adresse)', 'currency' => 'Monnaie', 'amount' => 'Montant', 'acceptance' => 'Point de dépôt', 'separate' => 'A détacher avant le versement'],
        'it' => ['receipt' => 'Ricevuta', 'payment' => 'Sezione pagamento', 'account' => 'Conto / Pagabile a', 'reference' => 'Riferimento', 'info' => 'Informazioni supplementari', 'payable_by' => 'Pagabile da', 'payable_by_blank' => 'Pagabile da (nome/indirizzo)', 'currency' => 'Valuta', 'amount' => 'Importo', 'acceptance' => 'Punto di accettazione', 'separate' => 'Da staccare prima del versamento'],
        'en' => ['receipt' => 'Receipt', 'payment' => 'Payment part', 'account' => 'Account / Payable to', 'reference' => 'Reference', 'info' => 'Additional information', 'payable_by' => 'Payable by', 'payable_by_blank' => 'Payable by (name/address)', 'currency' => 'Currency', 'amount' => 'Amount', 'acceptance' => 'Acceptance point', 'separate' => 'Separate before paying in'],
    ];

    /**
     * The QR code, 46 × 46 mm, with the Swiss cross in the middle.
     */
    public function svg(QrBill $bill): string
    {
        $matrix = (new QRCode(new QROptions(['eccLevel' => EccLevel::M, 'addQuietzone' => false])))
            ->addByteSegment($bill->payload())
            ->getQRMatrix();
        $size = $matrix->getSize();
        $path = '';

        foreach ($matrix->getMatrix(true) as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= "M{$x} {$y}h1v1h-1z";
                }
            }
        }

        // Swiss cross: 7 mm of 46 mm, white border, black square, white cross.
        $cross = $size * 7 / 46;
        $c = ($size - $cross) / 2;
        $u = $cross / 32;
        $f = fn (float $v): string => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$size.' '.$size.'" width="46mm" height="46mm" shape-rendering="crispEdges">'
            .'<rect width="'.$size.'" height="'.$size.'" fill="#fff"/>'
            .'<path d="'.$path.'" fill="#000"/>'
            .'<rect x="'.$f($c).'" y="'.$f($c).'" width="'.$f($cross).'" height="'.$f($cross).'" fill="#fff"/>'
            .'<rect x="'.$f($c + 2 * $u).'" y="'.$f($c + 2 * $u).'" width="'.$f(28 * $u).'" height="'.$f(28 * $u).'" fill="#000"/>'
            .'<rect x="'.$f($c + 13 * $u).'" y="'.$f($c + 8 * $u).'" width="'.$f(6 * $u).'" height="'.$f(16 * $u).'" fill="#fff"/>'
            .'<rect x="'.$f($c + 8 * $u).'" y="'.$f($c + 13 * $u).'" width="'.$f(16 * $u).'" height="'.$f(6 * $u).'" fill="#fff"/>'
            .'</svg>';
    }

    public function html(QrBill $bill, string $locale): string
    {
        $l = self::LABELS[$locale] ?? self::LABELS['de'];
        $e = fn (?string $v): string => e((string) $v);
        $address = fn (array $a): string => implode('<br>', array_map($e, array_filter([
            $a['name'],
            trim(($a['street'] ?? '').' '.($a['building'] ?? '')),
            trim(strtoupper((string) ($a['country'] ?? 'CH')) === 'CH' ? $a['zip'].' '.$a['city'] : strtoupper((string) $a['country']).'-'.$a['zip'].' '.$a['city']),
        ])));
        $account = $e(Iban::format($bill->iban)).'<br>'.$address($bill->creditor);
        $reference = $bill->referenceType === QrBill::TYPE_NON ? null : QrReference::format((string) $bill->reference);
        $amount = $bill->amountRp === null ? null : number_format($bill->amountRp / 100, 2, '.', ' ');
        $box = fn (int $w, int $h): string => '<div style="position:relative;width:'.$w.'mm;height:'.$h.'mm">'
            .'<span style="position:absolute;left:0;top:0;width:3mm;height:2mm;border-left:0.75pt solid #000;border-top:0.75pt solid #000"></span>'
            .'<span style="position:absolute;right:0;top:0;width:3mm;height:2mm;border-right:0.75pt solid #000;border-top:0.75pt solid #000"></span>'
            .'<span style="position:absolute;left:0;bottom:0;width:3mm;height:2mm;border-left:0.75pt solid #000;border-bottom:0.75pt solid #000"></span>'
            .'<span style="position:absolute;right:0;bottom:0;width:3mm;height:2mm;border-right:0.75pt solid #000;border-bottom:0.75pt solid #000"></span></div>';

        $receipt = '<div class="qr-title">'.$l['receipt'].'</div>'
            .'<div class="qr-r-h">'.$l['account'].'</div><div class="qr-r-v">'.$account.'</div>'
            .($reference !== null ? '<div class="qr-r-h">'.$l['reference'].'</div><div class="qr-r-v">'.$e($reference).'</div>' : '')
            .($bill->debtor !== null
                ? '<div class="qr-r-h">'.$l['payable_by'].'</div><div class="qr-r-v">'.$address($bill->debtor).'</div>'
                : '<div class="qr-r-h">'.$l['payable_by_blank'].'</div>'.$box(52, 20))
            .'<div class="qr-r-amount"><div><div class="qr-r-h">'.$l['currency'].'</div><div class="qr-r-v">'.$e($bill->currency).'</div></div>'
            .'<div><div class="qr-r-h">'.$l['amount'].'</div>'.($amount !== null ? '<div class="qr-r-v">'.$amount.'</div>' : $box(30, 10)).'</div></div>'
            .'<div class="qr-r-accept">'.$l['acceptance'].'</div>';

        $payment = '<div class="qr-p-left"><div class="qr-title">'.$l['payment'].'</div>'
            .'<div class="qr-code">'.$this->svg($bill).'</div>'
            .'<div class="qr-p-amount"><div><div class="qr-p-h">'.$l['currency'].'</div><div class="qr-p-v">'.$e($bill->currency).'</div></div>'
            .'<div><div class="qr-p-h">'.$l['amount'].'</div>'.($amount !== null ? '<div class="qr-p-v">'.$amount.'</div>' : $box(40, 15)).'</div></div></div>'
            .'<div class="qr-p-right">'
            .'<div class="qr-p-h">'.$l['account'].'</div><div class="qr-p-v">'.$account.'</div>'
            .($reference !== null ? '<div class="qr-p-h">'.$l['reference'].'</div><div class="qr-p-v">'.$e($reference).'</div>' : '')
            .(filled($bill->message) ? '<div class="qr-p-h">'.$l['info'].'</div><div class="qr-p-v">'.$e($bill->message).'</div>' : '')
            .($bill->debtor !== null
                ? '<div class="qr-p-h">'.$l['payable_by'].'</div><div class="qr-p-v">'.$address($bill->debtor).'</div>'
                : '<div class="qr-p-h">'.$l['payable_by_blank'].'</div>'.$box(65, 25))
            .'</div>';

        return <<<HTML
        <style>
            .qr-bill { position: relative; width: 210mm; height: 105mm; font-family: Arial, Helvetica, sans-serif; color: #000; box-sizing: border-box; border-top: 0.2mm dashed #000; }
            .qr-bill * { box-sizing: border-box; }
            .qr-bill .qr-separate { position: absolute; top: -4mm; left: 0; width: 210mm; text-align: center; font-size: 7pt; }
            .qr-bill .qr-receipt { position: absolute; left: 0; top: 0; width: 62mm; height: 100%; padding: 5mm; border-right: 0.2mm dashed #000; }
            .qr-bill .qr-payment { position: absolute; left: 62mm; top: 0; width: 148mm; height: 100%; padding: 5mm; }
            .qr-bill .qr-title { font-size: 11pt; font-weight: bold; margin-bottom: 3.5mm; }
            .qr-bill .qr-r-h { font-size: 6pt; font-weight: bold; line-height: 9pt; }
            .qr-bill .qr-r-v { font-size: 8pt; line-height: 9pt; margin-bottom: 9pt; }
            .qr-bill .qr-r-amount { position: absolute; left: 5mm; top: 68mm; display: flex; gap: 6mm; }
            .qr-bill .qr-r-accept { position: absolute; right: 5mm; top: 82mm; font-size: 6pt; font-weight: bold; }
            .qr-bill .qr-p-left { position: absolute; left: 5mm; top: 5mm; width: 51mm; }
            .qr-bill .qr-code { margin-top: 1.5mm; width: 46mm; height: 46mm; }
            .qr-bill .qr-p-amount { margin-top: 6mm; display: flex; gap: 6mm; }
            .qr-bill .qr-p-right { position: absolute; left: 56mm; top: 5mm; width: 87mm; }
            .qr-bill .qr-p-h { font-size: 8pt; font-weight: bold; line-height: 11pt; }
            .qr-bill .qr-p-v { font-size: 10pt; line-height: 11pt; margin-bottom: 11pt; }
        </style>
        <div class="qr-bill">
            <div class="qr-separate">✂ {$l['separate']}</div>
            <div class="qr-receipt">{$receipt}</div>
            <div class="qr-payment">{$payment}</div>
        </div>
        HTML;
    }
}
