<?php

namespace App\Domain\Parties\Enums;

use Filament\Support\Contracts\HasLabel;

enum PartyKind: string implements HasLabel
{
    case Person = 'person';
    case Company = 'company';

    public function getLabel(): string
    {
        return match ($this) {
            self::Person => __('Private person'),
            self::Company => __('Company'),
        };
    }
}
