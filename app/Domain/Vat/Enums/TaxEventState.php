<?php

namespace App\Domain\Vat\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Automation proposes, rules decide: auto = booked; confirm = plausible, a user confirms;
 * blocked = excluded from the period until resolved (missing data, unsupported case).
 */
enum TaxEventState: string implements HasColor, HasLabel
{
    case Auto = 'auto';
    case Confirm = 'confirm';
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Auto => __('Booked'),
            self::Confirm => __('Please confirm'),
            self::Blocked => __('Blocked'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Auto => 'success',
            self::Confirm => 'warning',
            self::Blocked => 'danger',
        };
    }
}
