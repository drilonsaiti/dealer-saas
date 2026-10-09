<?php

namespace App\Domain\Vat\Enums;

use Filament\Support\Contracts\HasLabel;

enum VatMethod: string implements HasLabel
{
    /** Saldosteuersatzmethode: the approved net tax rate on the gross taxable turnover, no input tax. */
    case NetTaxRate = 'net_tax_rate';

    /** Effektive Methode: VAT on turnover minus deductible input tax (prepared, not yet released). */
    case Effective = 'effective';

    public function getLabel(): string
    {
        return match ($this) {
            self::NetTaxRate => __('Net tax rate method'),
            self::Effective => __('Effective method'),
        };
    }
}
