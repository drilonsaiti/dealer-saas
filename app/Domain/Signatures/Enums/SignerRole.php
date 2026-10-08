<?php

namespace App\Domain\Signatures\Enums;

use Filament\Support\Contracts\HasLabel;

enum SignerRole: string implements HasLabel
{
    case Customer = 'customer';
    case Dealer = 'dealer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Customer => __('Customer'),
            self::Dealer => __('Dealer'),
        };
    }
}
