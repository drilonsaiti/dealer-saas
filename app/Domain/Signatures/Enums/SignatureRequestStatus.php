<?php

namespace App\Domain\Signatures\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SignatureRequestStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Waiting for signatures'),
            self::Completed => __('Signed'),
            self::Cancelled => __('Withdrawn'),
            self::Expired => __('Expired'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Completed => 'success',
            self::Cancelled, self::Expired => 'gray',
        };
    }
}
