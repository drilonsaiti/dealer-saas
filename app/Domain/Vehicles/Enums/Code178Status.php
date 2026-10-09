<?php

namespace App\Domain\Vehicles\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Code 178 in the registration document: no change of holder without the bank's consent.
 */
enum Code178Status: string implements HasColor, HasLabel
{
    case None = 'none';
    case Entered = 'entered';
    case Cleared = 'cleared';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => __('No code 178'),
            self::Entered => __('Code 178 entered'),
            self::Cleared => __('Code 178 cleared'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Entered => 'danger',
            self::Cleared => 'success',
        };
    }
}
