<?php

namespace App\Domain\Accounting\Support;

/**
 * One booking line: debit account, credit account, amount (always positive).
 */
final readonly class JournalEntry
{
    public function __construct(
        public string $date, // Y-m-d
        public string $voucher,
        public string $debit,
        public string $credit,
        public int $amountRp,
        public ?string $vatRate,
        public string $text,
        public ?string $fileNumber,
        public string $sourceType,
        public string $sourceId,
    ) {}
}
