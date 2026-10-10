<?php

namespace App\Domain\Preparation\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * A repair order to a workshop: estimate → approved → done (actual cost booked), or cancelled.
 */
enum RepairOrderStatus: string implements HasColor, HasLabel
{
    case Estimate = 'estimate';
    case Approved = 'approved';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Estimate => __('Estimate'),
            self::Approved => __('Approved'),
            self::Done => __('Done'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Estimate => 'gray',
            self::Approved => 'warning',
            self::Done => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Estimate, self::Approved], true);
    }
}
