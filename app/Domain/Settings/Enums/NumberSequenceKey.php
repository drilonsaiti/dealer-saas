<?php

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasLabel;

enum NumberSequenceKey: string implements HasLabel
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Offer = 'offer';
    case Contract = 'contract';
    case StockCycle = 'stock_cycle';
    case HandoverProtocol = 'handover_protocol';

    public function getLabel(): string
    {
        return match ($this) {
            self::Invoice => __('Invoice'),
            self::CreditNote => __('Credit note'),
            self::Offer => __('Offer'),
            self::Contract => __('Contract'),
            self::StockCycle => __('Stock cycle'),
            self::HandoverProtocol => __('Handover protocol'),
        };
    }

    public function defaultPattern(): string
    {
        return match ($this) {
            self::Invoice => 'RE-{00000}',
            self::CreditNote => 'GS-{00000}',
            self::Offer => 'OF-{00000}',
            self::Contract => 'KV-{00000}',
            self::StockCycle => '{YYYY}-{0000}',
            self::HandoverProtocol => 'UP-{00000}',
        };
    }

    public function resetsYearlyByDefault(): bool
    {
        return $this === self::StockCycle;
    }
}
