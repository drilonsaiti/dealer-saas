<?php

namespace App\Domain\Financing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Leasing or credit through a financing partner (bank).
 */
enum FinancingKind: string implements HasLabel
{
    case Leasing = 'leasing';
    case Credit = 'credit';

    public function getLabel(): string
    {
        return match ($this) {
            self::Leasing => __('Leasing'),
            self::Credit => __('Credit'),
        };
    }
}
