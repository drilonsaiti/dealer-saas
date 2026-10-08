<?php

namespace App\Domain\Vehicles\Enums;

use Filament\Support\Contracts\HasLabel;

enum BodyType: string implements HasLabel
{
    case Sedan = 'sedan';
    case Estate = 'estate';
    case Hatchback = 'hatchback';
    case Suv = 'suv';
    case Coupe = 'coupe';
    case Convertible = 'convertible';
    case Van = 'van';
    case Pickup = 'pickup';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sedan => __('Sedan'),
            self::Estate => __('Estate'),
            self::Hatchback => __('Hatchback'),
            self::Suv => __('SUV / off-road'),
            self::Coupe => __('Coupé'),
            self::Convertible => __('Convertible'),
            self::Van => __('Van / minibus'),
            self::Pickup => __('Pick-up'),
            self::Other => __('Other'),
        };
    }
}
