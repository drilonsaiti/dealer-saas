<?php

namespace App\Domain\Documents\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentSource: string implements HasLabel
{
    case Generated = 'generated';
    case Upload = 'upload';
    case Email = 'email';
    case Import = 'import';

    public function getLabel(): string
    {
        return match ($this) {
            self::Generated => __('Generated'),
            self::Upload => __('Upload'),
            self::Email => __('Email'),
            self::Import => __('Import'),
        };
    }
}
