<?php

namespace App\Domain\Financing\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * A repurchase obligation from a leasing contract.
 */
enum BuybackStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Exercised = 'exercised';
    case Released = 'released';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Exercised => __('Bought back'),
            self::Released => __('Released'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Exercised => 'success',
            self::Released => 'gray',
        };
    }
}
