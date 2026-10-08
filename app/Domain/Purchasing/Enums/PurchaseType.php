<?php

namespace App\Domain\Purchasing\Enums;

use Filament\Support\Contracts\HasLabel;

enum PurchaseType: string implements HasLabel
{
    case Direct = 'direct';
    case TradeIn = 'trade_in';
    case Buyback = 'buyback';
    case Consignment = 'consignment';

    public function getLabel(): string
    {
        return match ($this) {
            self::Direct => __('Direct purchase'),
            self::TradeIn => __('Trade-in'),
            self::Buyback => __('Buy-back'),
            self::Consignment => __('Consignment'),
        };
    }
}
