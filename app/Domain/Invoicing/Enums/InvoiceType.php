<?php

namespace App\Domain\Invoicing\Enums;

use App\Domain\Settings\Enums\NumberSequenceKey;
use Filament\Support\Contracts\HasLabel;

enum InvoiceType: string implements HasLabel
{
    /** Anzahlungsrechnung: the deposit agreed in the sales contract. */
    case Deposit = 'deposit';

    /** Schlussrechnung: the whole sale, minus the deposits already invoiced. */
    case Final = 'final';

    /** Any other invoice (parts, services, workshop). */
    case Standard = 'standard';

    /** Gutschrift: always linked to the invoice it corrects. */
    case CreditNote = 'credit_note';

    public function getLabel(): string
    {
        return $this->labelIn(app()->getLocale());
    }

    public function labelIn(string $locale): string
    {
        return (string) __(match ($this) {
            self::Deposit => 'Deposit invoice',
            self::Final => 'Final invoice',
            self::Standard => 'Invoice',
            self::CreditNote => 'Credit note',
        }, locale: $locale);
    }

    public function numberSequence(): NumberSequenceKey
    {
        return $this === self::CreditNote ? NumberSequenceKey::CreditNote : NumberSequenceKey::Invoice;
    }
}
