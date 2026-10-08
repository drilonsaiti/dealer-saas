<?php

namespace App\Domain\Sales\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SaleStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Reserved = 'reserved';
    case Contracted = 'contracted';
    case Invoiced = 'invoiced';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Reserved => __('Reserved'),
            self::Contracted => __('Contracted'),
            self::Invoiced => __('Invoiced'),
            self::Delivered => __('Delivered'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Reserved => 'warning',
            self::Contracted => 'primary',
            self::Invoiced => 'info',
            self::Delivered => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Counts as a sale of the car (not cancelled, not just a draft).
     */
    public function isActive(): bool
    {
        return $this !== self::Cancelled;
    }
}
