<?php

namespace App\Domain\Purchasing\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::PartiallyPaid => __('Partially paid'),
            self::Paid => __('Paid'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
        };
    }
}
