<?php

namespace App\Domain\Documents\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OcrStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
    case NotNeeded = 'not_needed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('OCR pending'),
            self::Done => __('Searchable'),
            self::Failed => __('OCR failed'),
            self::NotNeeded => __('Not needed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Done => 'success',
            self::Failed => 'danger',
            self::NotNeeded => 'gray',
        };
    }
}
