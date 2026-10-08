<?php

namespace App\Domain\Parties\Enums;

use Filament\Support\Contracts\HasLabel;

enum Salutation: string implements HasLabel
{
    case Mr = 'mr';
    case Ms = 'ms';
    case Company = 'company';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mr => __('Mr'),
            self::Ms => __('Ms'),
            self::Company => __('Company'),
        };
    }
}
