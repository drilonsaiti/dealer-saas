<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Enums\MatchStatus;
use App\Domain\Payments\Models\BankTransaction;
use App\Domain\Payments\Support\CamtParser;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Support\Iban;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports a camt.053/054 file for one bank account. Bookings already imported are skipped.
 * Incoming payments with the QR or creditor reference of an open invoice are booked at once;
 * others are proposed (same open amount, one invoice) or wait in the list for assignment.
 */
class ImportBankStatement
{
    public function __construct(private readonly MatchBankTransaction $match) {}

    /**
     * @return array{new: int, skipped: int, matched: int, proposed: int, open: int}
     */
    public function __invoke(BankAccount $account, string $path, string $fileName): array
    {
        $parsed = CamtParser::parse((string) file_get_contents($path));
        $accountIbans = array_filter([Iban::normalize($account->iban), $account->qr_iban ? Iban::normalize($account->qr_iban) : null]);

        if ($parsed['iban'] !== null && ! in_array($parsed['iban'], $accountIbans, true)) {
            throw new BusinessRuleException(__('This statement is for account :iban, not for :account.', ['iban' => Iban::format($parsed['iban']), 'account' => $account->label]));
        }

        $summary = ['new' => 0, 'skipped' => 0, 'matched' => 0, 'proposed' => 0, 'open' => 0];

        DB::transaction(function () use ($account, $parsed, $fileName, &$summary): void {
            foreach ($parsed['entries'] as $entry) {
                if (BankTransaction::query()->where('entry_key', $entry['entry_key'])->exists()) {
                    $summary['skipped']++;

                    continue;
                }

                $transaction = BankTransaction::create([...$entry, 'bank_account_id' => $account->getKey(), 'source_file' => Str::limit($fileName, 250, '')]);
                $summary['new']++;

                $status = $this->match->auto($transaction);
                $summary[match ($status) {
                    MatchStatus::Matched => 'matched',
                    MatchStatus::Proposed => 'proposed',
                    default => 'open',
                }]++;
            }
        });

        return $summary;
    }
}
