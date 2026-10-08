<?php

namespace App\Domain\Vehicles\Enums;

use Filament\Support\Contracts\HasLabel;

enum TyreSeason: string implements HasLabel
{
    case Summer = 'summer';
    case Winter = 'winter';
    case AllSeason = 'all_season';

    public function getLabel(): string
    {
        return match ($this) {
            self::Summer => __('Summer tyres'),
            self::Winter => __('Winter tyres'),
            self::AllSeason => __('All-season tyres'),
        };
    }
}
