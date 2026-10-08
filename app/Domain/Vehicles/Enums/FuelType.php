<?php

namespace App\Domain\Vehicles\Enums;

use Filament\Support\Contracts\HasLabel;

enum FuelType: string implements HasLabel
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Hybrid = 'hybrid';
    case PlugInHybrid = 'plug_in_hybrid';
    case Electric = 'electric';
    case Gas = 'gas';
    case Hydrogen = 'hydrogen';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Petrol => __('Petrol'),
            self::Diesel => __('Diesel'),
            self::Hybrid => __('Hybrid'),
            self::PlugInHybrid => __('Plug-in hybrid'),
            self::Electric => __('Electric'),
            self::Gas => __('Natural gas'),
            self::Hydrogen => __('Hydrogen'),
            self::Other => __('Other'),
        };
    }
}
