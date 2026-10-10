<?php

namespace App\Domain\Listings\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * State of a listing on one channel.
 */
enum PublicationStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Published = 'published';
    case Failed = 'failed';
    case Removed = 'removed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Waiting'),
            self::Published => __('Online'),
            self::Failed => __('Failed'),
            self::Removed => __('Removed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Published => 'success',
            self::Failed => 'danger',
            self::Removed => 'gray',
        };
    }
}
