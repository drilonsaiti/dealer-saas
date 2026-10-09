<?php

namespace App\Domain\Payments\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MatchStatus: string implements HasColor, HasLabel
{
    /** Matched by QR/creditor reference and booked as payment. */
    case Matched = 'matched';

    /** A likely invoice was found (amount and name); waits for confirmation. */
    case Proposed = 'proposed';

    case Unmatched = 'unmatched';

    /** Not a customer payment (fees, own transfers...). */
    case Ignored = 'ignored';

    public function getLabel(): string
    {
        return match ($this) {
            self::Matched => __('Booked'),
            self::Proposed => __('Please confirm'),
            self::Unmatched => __('Not assigned'),
            self::Ignored => __('Ignored'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Matched => 'success',
            self::Proposed => 'warning',
            self::Unmatched => 'danger',
            self::Ignored => 'gray',
        };
    }
}
