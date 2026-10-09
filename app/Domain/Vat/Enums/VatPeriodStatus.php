<?php

namespace App\Domain\Vat\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Each step is its own status: an XML export is not a submission, a submission is not a payment.
 */
enum VatPeriodStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Closed = 'closed';
    case Exported = 'exported';
    case Submitted = 'submitted';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Closed => __('Closed'),
            self::Exported => __('Exported'),
            self::Submitted => __('Submitted'),
            self::Paid => __('Paid'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Closed => 'info',
            self::Exported => 'info',
            self::Submitted => 'primary',
            self::Paid => 'success',
        };
    }

    public function isClosed(): bool
    {
        return $this !== self::Open;
    }
}
