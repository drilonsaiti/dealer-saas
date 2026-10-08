<?php

namespace App\Domain\Purchasing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * VAT situation of a purchase (VAT fact 2). A private purchase never implies a VAT-free or
 * margin-taxed sale; "unknown" is blocked once the VAT module evaluates purchases.
 */
enum VatSituation: string implements HasLabel
{
    case PrivateNoVat = 'private_no_vat';
    case CompanyVatShown = 'company_vat_shown';
    case CompanyNoVatShown = 'company_no_vat_shown';
    case ForeignVat = 'foreign_vat';
    case Import = 'import';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::PrivateNoVat => __('Private seller, no VAT'),
            self::CompanyVatShown => __('Company, VAT shown'),
            self::CompanyNoVatShown => __('Company, no VAT shown'),
            self::ForeignVat => __('Foreign VAT'),
            self::Import => __('Import'),
            self::Unknown => __('Unknown'),
        };
    }
}
