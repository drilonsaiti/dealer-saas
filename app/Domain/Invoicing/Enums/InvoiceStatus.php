<?php

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InvoiceStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Issued => __('Open'),
            self::PartiallyPaid => __('Partially paid'),
            self::Paid => __('Paid'),
            self::Cancelled => __('Cancelled by credit note'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued => 'warning',
            self::PartiallyPaid => 'info',
            self::Paid => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Issued invoices are immutable; only drafts can be changed or deleted.
     */
    public function isIssued(): bool
    {
        return $this !== self::Draft;
    }
}
