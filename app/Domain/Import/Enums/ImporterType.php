<?php

namespace App\Domain\Import\Enums;

use Filament\Support\Contracts\HasLabel;

enum ImporterType: string implements HasLabel
{
    case Vehicles = 'vehicles';
    case Costs = 'costs';
    case Documents = 'documents';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vehicles => __('Vehicles and vehicle files'),
            self::Costs => __('Costs'),
            self::Documents => __('Document folder (ZIP)'),
        };
    }
}
