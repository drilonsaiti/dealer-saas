<?php

namespace App\Domain\Purchasing\Enums;

use Filament\Support\Contracts\HasLabel;

enum SellerKind: string implements HasLabel
{
    case Private = 'private';
    case Company = 'company';
    case Dealer = 'dealer';
    case Auction = 'auction';

    public function getLabel(): string
    {
        return match ($this) {
            self::Private => __('Private person'),
            self::Company => __('Company'),
            self::Dealer => __('Dealer'),
            self::Auction => __('Auction'),
        };
    }
}
