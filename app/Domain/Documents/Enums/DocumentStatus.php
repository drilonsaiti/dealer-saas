<?php

namespace App\Domain\Documents\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Final = 'final';
    case OutForSignature = 'out_for_signature';
    case Signed = 'signed';
    case Superseded = 'superseded';
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Final => __('Final'),
            self::OutForSignature => __('Out for signature'),
            self::Signed => __('Signed'),
            self::Superseded => __('Superseded'),
            self::Void => __('Void'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Final => 'success',
            self::OutForSignature => 'warning',
            self::Signed => 'primary',
            self::Superseded => 'gray',
            self::Void => 'danger',
        };
    }

    /**
     * Signed documents never change; corrections are new documents.
     */
    public function isLocked(): bool
    {
        return $this === self::Signed;
    }
}
