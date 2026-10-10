<?php

namespace App\Domain\Inbox\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmailStatus: string implements HasColor, HasLabel
{
    case Received = 'received';
    case Draft = 'draft';
    case Sent = 'sent';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Received => __('Received'),
            self::Draft => __('Draft'),
            self::Sent => __('Sent'),
            self::Failed => __('Failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Received => 'info',
            self::Draft => 'warning',
            self::Sent => 'success',
            self::Failed => 'danger',
        };
    }
}
