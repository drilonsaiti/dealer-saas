<?php

namespace App\Domain\Vehicles\Enums;

use Filament\Support\Contracts\HasLabel;

enum DriveType: string implements HasLabel
{
    case Front = 'front';
    case Rear = 'rear';
    case AllWheel = 'all_wheel';

    public function getLabel(): string
    {
        return match ($this) {
            self::Front => __('Front-wheel drive'),
            self::Rear => __('Rear-wheel drive'),
            self::AllWheel => __('All-wheel drive'),
        };
    }
}
