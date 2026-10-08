<?php

namespace App\Domain\Parties\Enums;

use Filament\Support\Contracts\HasLabel;

enum PartyRole: string implements HasLabel
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case PrivateSeller = 'private_seller';
    case FinancingPartner = 'financing_partner';
    case WarrantyProvider = 'warranty_provider';
    case Workshop = 'workshop';
    case Transporter = 'transporter';
    case Auction = 'auction';

    public function getLabel(): string
    {
        return match ($this) {
            self::Customer => __('Customer'),
            self::Supplier => __('Supplier'),
            self::PrivateSeller => __('Private seller'),
            self::FinancingPartner => __('Leasing / financing partner'),
            self::WarrantyProvider => __('Warranty provider'),
            self::Workshop => __('Workshop'),
            self::Transporter => __('Transporter'),
            self::Auction => __('Auction'),
        };
    }
}
