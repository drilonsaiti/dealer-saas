<?php

namespace App\Domain\Preparation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What kind of damage.
 */
enum DamageKind: string implements HasLabel
{
    case Scratch = 'scratch';
    case Dent = 'dent';
    case StoneChip = 'stone_chip';
    case Crack = 'crack';
    case Rust = 'rust';
    case Stain = 'stain';
    case Wear = 'wear';
    case Defect = 'defect';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scratch => __('Scratch'),
            self::Dent => __('Dent'),
            self::StoneChip => __('Stone chip'),
            self::Crack => __('Crack'),
            self::Rust => __('Rust'),
            self::Stain => __('Stain'),
            self::Wear => __('Wear'),
            self::Defect => __('Technical defect'),
            self::Other => __('Other'),
        };
    }
}
