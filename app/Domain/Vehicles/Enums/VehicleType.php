<?php

namespace App\Domain\Vehicles\Enums;

use Filament\Support\Contracts\HasLabel;

enum VehicleType: string implements HasLabel
{
    case PassengerCar = 'passenger_car';
    case LightCommercial = 'light_commercial';
    case Motorhome = 'motorhome';
    case Motorcycle = 'motorcycle';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::PassengerCar => __('Passenger car'),
            self::LightCommercial => __('Light commercial vehicle'),
            self::Motorhome => __('Motorhome'),
            self::Motorcycle => __('Motorcycle'),
            self::Other => __('Other'),
        };
    }
}
