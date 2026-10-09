<?php

namespace App\Domain\Vat\Enums;

/**
 * What a VAT code means for the tax (VAT requirements: never a single "with/without VAT"
 * flag). "No tax shown", "export exempt" and "excluded" all print no VAT, but are different.
 */
enum VatCodeKind: string
{
    case Taxable = 'taxable';
    case NoTaxShown = 'no_tax_shown';
    case ExportExempt = 'export_exempt';
    case Excluded = 'excluded';
    case ReverseCharge = 'reverse_charge';
    case Import = 'import';

    public function hasRate(): bool
    {
        return $this === self::Taxable;
    }
}
