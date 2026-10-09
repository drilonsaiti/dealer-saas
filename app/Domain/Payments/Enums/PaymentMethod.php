<?php

namespace App\Domain\Payments\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Trade-in offsets and financing payouts are payment methods too, so the open balance of a
 * sale is always exact.
 */
enum PaymentMethod: string implements HasLabel
{
    case Bank = 'bank';
    case Cash = 'cash';
    case Card = 'card';
    case Twint = 'twint';
    case FinancingPayout = 'financing_payout';
    case TradeInOffset = 'trade_in_offset';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bank => __('Bank transfer'),
            self::Cash => __('Cash'),
            self::Card => __('Card'),
            self::Twint => __('TWINT'),
            self::FinancingPayout => __('Financing payout'),
            self::TradeInOffset => __('Trade-in offset'),
        };
    }
}
