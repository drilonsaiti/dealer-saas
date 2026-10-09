<?php

namespace App\Domain\Warranty\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * A warranty claim from report to settlement.
 */
enum ClaimStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Closed => __('Settled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::Closed => 'success',
        };
    }
}
