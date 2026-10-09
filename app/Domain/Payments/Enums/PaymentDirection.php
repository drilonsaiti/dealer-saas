<?php

namespace App\Domain\Payments\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentDirection: string implements HasLabel
{
    case In = 'in';
    case Out = 'out';

    public function getLabel(): string
    {
        return $this === self::In ? __('Incoming') : __('Outgoing');
    }
}
