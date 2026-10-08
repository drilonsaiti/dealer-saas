<?php

namespace App\Domain\Vehicles\Enums;

use Filament\Support\Contracts\HasLabel;

enum Transmission: string implements HasLabel
{
    case Manual = 'manual';
    case Automatic = 'automatic';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => __('Manual'),
            self::Automatic => __('Automatic'),
        };
    }
}
