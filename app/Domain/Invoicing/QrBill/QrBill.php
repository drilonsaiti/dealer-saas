<?php

namespace App\Domain\Invoicing\QrBill;

use App\Domain\Settings\Support\Iban;
use InvalidArgumentException;

/**
 * Content of a Swiss QR bill (Swiss Payment Standards, implementation guidelines 2.3,
 * structured addresses only). payload() is the text inside the QR code.
 */
final class QrBill
{
    public const TYPE_QRR = 'QRR';

    public const TYPE_SCOR = 'SCOR';

    public const TYPE_NON = 'NON';

    /**
     * @param  array{name: string, street?: string|null, building?: string|null, zip: string, city: string, country?: string|null}  $creditor
     * @param  array{name: string, street?: string|null, building?: string|null, zip: string, city: string, country?: string|null}|null  $debtor
     */
    public function __construct(
        public readonly string $iban,
        public readonly array $creditor,
        public readonly ?int $amountRp,
        public readonly ?array $debtor,
        public readonly string $referenceType,
        public readonly ?string $reference,
        public readonly ?string $message = null,
        public readonly string $currency = 'CHF',
    ) {
        $this->validate();
    }

    public function payload(): string
    {
        $lines = [
            'SPC', '0200', '1',
            Iban::normalize($this->iban),
            ...$this->address($this->creditor),
            '', '', '', '', '', '', '', // ultimate creditor (reserved, must stay empty)
            $this->amountRp === null ? '' : number_format($this->amountRp / 100, 2, '.', ''),
            $this->currency,
            ...($this->debtor === null ? ['', '', '', '', '', '', ''] : $this->address($this->debtor)),
            $this->referenceType,
            (string) $this->reference,
            self::clip((string) $this->message, 140),
            'EPD',
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, string|null>  $address
     * @return list<string>
     */
    private function address(array $address): array
    {
        return [
            'S',
            self::clip((string) $address['name'], 70),
            self::clip((string) ($address['street'] ?? ''), 70),
            self::clip((string) ($address['building'] ?? ''), 16),
            self::clip((string) $address['zip'], 16),
            self::clip((string) $address['city'], 35),
            strtoupper((string) ($address['country'] ?? 'CH')),
        ];
    }

    private function validate(): void
    {
        $iban = Iban::normalize($this->iban);

        if (! Iban::isValid($iban) || ! Iban::isSwissOrLiechtenstein($iban)) {
            throw new InvalidArgumentException('The QR bill needs a Swiss or Liechtenstein IBAN.');
        }

        $isQrIban = Iban::isQrIban($iban);

        if ($this->referenceType === self::TYPE_QRR && (! $isQrIban || ! QrReference::isValidQrr((string) $this->reference))) {
            throw new InvalidArgumentException('A QR reference needs a QR-IBAN and a valid 27-digit reference.');
        }

        if ($this->referenceType !== self::TYPE_QRR && $isQrIban) {
            throw new InvalidArgumentException('A QR-IBAN can only be used with a QR reference.');
        }

        if ($this->referenceType === self::TYPE_SCOR && ! QrReference::isValidScor((string) $this->reference)) {
            throw new InvalidArgumentException('Invalid creditor reference.');
        }

        if (blank($this->creditor['name']) || blank($this->creditor['zip']) || blank($this->creditor['city'])) {
            throw new InvalidArgumentException('The creditor needs name, postcode and town.');
        }

        if ($this->amountRp !== null && ($this->amountRp < 1 || $this->amountRp > 99_999_999_999)) {
            throw new InvalidArgumentException('The amount must be between 0.01 and 999 999 999.99.');
        }
    }

    private static function clip(string $value, int $max): string
    {
        // Line breaks would shift every following field.
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $value)), 0, $max);
    }
}
