<?php

namespace App\Domain\Vat\Enums;

use Filament\Support\Contracts\HasLabel;

enum VatBasis: string implements HasLabel
{
    /** Vereinbarte Entgelte (legal standard): the invoice date decides the period. */
    case Agreed = 'agreed';

    /** Vereinnahmte Entgelte (only with ESTV approval): the payment date decides. */
    case Received = 'received';

    public function getLabel(): string
    {
        return match ($this) {
            self::Agreed => __('Agreed consideration (invoice date)'),
            self::Received => __('Received consideration (payment date)'),
        };
    }

    /** eCH-0217 formOfReporting. */
    public function formOfReporting(): int
    {
        return $this === self::Agreed ? 1 : 2;
    }
}
