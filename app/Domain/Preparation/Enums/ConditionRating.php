<?php

namespace App\Domain\Preparation\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Rating of one area in a condition report.
 */
enum ConditionRating: string implements HasColor, HasLabel
{
    case Ok = 'ok';
    case Attention = 'attention';
    case Defect = 'defect';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ok => __('OK'),
            self::Attention => __('Attention'),
            self::Defect => __('Defect'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Attention => 'warning',
            self::Defect => 'danger',
        };
    }
}
