<?php

namespace App\Domain\Sales\Enums;

use Filament\Support\Contracts\HasLabel;

enum SaleItemKind: string implements HasLabel
{
    case Accessory = 'accessory';
    case Service = 'service';
    case Tyres = 'tyres';
    case Warranty = 'warranty';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Accessory => __('Accessory'),
            self::Service => __('Service'),
            self::Tyres => __('Tyres'),
            self::Warranty => __('Warranty'),
            self::Other => __('Other'),
        };
    }
}
