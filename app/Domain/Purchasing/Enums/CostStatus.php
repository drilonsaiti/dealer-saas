<?php

namespace App\Domain\Purchasing\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CostStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Confirmed => __('Confirmed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Confirmed => 'success',
        };
    }
}
