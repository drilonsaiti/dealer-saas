<?php

namespace App\Domain\Import\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ImportRowAction: string implements HasColor, HasLabel
{
    case Create = 'create';
    case Update = 'update';
    case Skip = 'skip';
    case Unassigned = 'unassigned';
    case Error = 'error';
    case RolledBack = 'rolled_back';

    public function getLabel(): string
    {
        return match ($this) {
            self::Create => __('New'),
            self::Update => __('Update'),
            self::Skip => __('Skipped'),
            self::Unassigned => __('Not assigned'),
            self::Error => __('Error'),
            self::RolledBack => __('Rolled back'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Create => 'success',
            self::Update => 'info',
            self::Skip => 'gray',
            self::Unassigned => 'warning',
            self::Error => 'danger',
            self::RolledBack => 'gray',
        };
    }
}
