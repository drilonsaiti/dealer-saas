<?php

namespace App\Domain\Documents\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RequiredDocumentStatus: string implements HasColor, HasLabel
{
    case Missing = 'missing';
    case Requested = 'requested';
    case Present = 'present';
    case NotRequired = 'not_required';

    public function getLabel(): string
    {
        return match ($this) {
            self::Missing => __('Missing'),
            self::Requested => __('Requested'),
            self::Present => __('Present'),
            self::NotRequired => __('Not required'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Missing => 'danger',
            self::Requested => 'warning',
            self::Present => 'success',
            self::NotRequired => 'gray',
        };
    }
}
