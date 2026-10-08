<?php

namespace App\Domain\Sales\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentType: string implements HasLabel
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Leasing = 'leasing';
    case Credit = 'credit';
    case Mixed = 'mixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::Bank => __('Bank transfer'),
            self::Leasing => __('Leasing'),
            self::Credit => __('Credit'),
            self::Mixed => __('Mixed'),
        };
    }
}
