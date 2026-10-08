<?php

namespace App\Domain\Signatures\Enums;

use Filament\Support\Contracts\HasLabel;

enum SigningMethod: string implements HasLabel
{
    /** In the showroom on the iPad or PC; the salesperson confirms the ID check. */
    case OnDevice = 'on_device';

    /** Remotely: link by email, one-time code to the phone (or email) on file. */
    case Link = 'link';

    public function getLabel(): string
    {
        return match ($this) {
            self::OnDevice => __('Here on this device'),
            self::Link => __('By link to the customer'),
        };
    }
}
