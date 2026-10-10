<?php

namespace App\Domain\Preparation\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How bad a damage is.
 */
enum DamageSeverity: string implements HasColor, HasLabel
{
    case Minor = 'minor';
    case Medium = 'medium';
    case Major = 'major';

    public function getLabel(): string
    {
        return match ($this) {
            self::Minor => __('Minor'),
            self::Medium => __('Medium'),
            self::Major => __('Major'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Minor => 'gray',
            self::Medium => 'warning',
            self::Major => 'danger',
        };
    }
}
