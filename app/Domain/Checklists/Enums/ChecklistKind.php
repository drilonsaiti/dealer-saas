<?php

namespace App\Domain\Checklists\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Checklists generated from tenant templates.
 */
enum ChecklistKind: string implements HasLabel
{
    case Handover = 'handover';
    case FinancingPartner = 'financing_partner';

    public function getLabel(): string
    {
        return match ($this) {
            self::Handover => __('Handover'),
            self::FinancingPartner => __('Financing partner'),
        };
    }
}
