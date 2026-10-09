<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\MatchStatus;
use App\Domain\Payments\Enums\PaymentDirection;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Models\BankTransaction;
use App\Domain\Payments\Models\Payment;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a bank booking into a payment on an invoice: automatically by reference, or after
 * the user confirmed a proposal or chose the invoice.
 */
class MatchBankTransaction
{
    public function __construct(private readonly RecordPayment $payments) {}

    public function auto(BankTransaction $transaction): MatchStatus
    {
        if (! $transaction->isCredit()) {
            return $transaction->match_status;
        }

        $invoice = $transaction->reference === null ? null
            : Invoice::query()->open()->where('qr_reference', $transaction->reference)->first();

        if ($invoice !== null) {
            $this->book($transaction, $invoice);

            return MatchStatus::Matched;
        }

        $candidates = Invoice::query()->open()->get()->filter(fn (Invoice $i): bool => $i->openRp() === $transaction->amount_rp);

        if ($candidates->count() === 1) {
            $transaction->forceFill(['match_status' => MatchStatus::Proposed, 'proposed_invoice_id' => $candidates->first()->getKey()])->save();

            return MatchStatus::Proposed;
        }

        return MatchStatus::Unmatched;
    }

    /**
     * Books the (incoming) booking on the invoice; money beyond the open amount stays
     * unallocated on the payment.
     */
    public function book(BankTransaction $transaction, Invoice $invoice): Payment
    {
        if ($transaction->payment_id !== null) {
            throw new BusinessRuleException(__('This booking is already assigned.'));
        }

        if (! $transaction->isCredit()) {
            throw new BusinessRuleException(__('Only incoming money can be assigned to an invoice.'));
        }

        return DB::transaction(function () use ($transaction, $invoice): Payment {
            $amount = min($transaction->amount_rp, $invoice->openRp());

            if ($amount <= 0) {
                throw new BusinessRuleException(__('This invoice has nothing open.'));
            }

            $payment = ($this->payments)([
                'direction' => PaymentDirection::In,
                'paid_on' => ($transaction->value_on ?? $transaction->booked_on)->toDateString(),
                'amount_rp' => $transaction->amount_rp,
                'method' => PaymentMethod::Bank,
                'party_id' => $invoice->recipient_party_id,
                'bank_account_id' => $transaction->bank_account_id,
                'reference' => $transaction->reference,
                'bank_transaction_id' => $transaction->getKey(),
                'notes' => $transaction->counterparty,
            ], [[$invoice, $amount]]);

            $transaction->forceFill(['match_status' => MatchStatus::Matched, 'payment_id' => $payment->getKey(), 'proposed_invoice_id' => null])->save();

            return $payment;
        });
    }

    public function ignore(BankTransaction $transaction): void
    {
        if ($transaction->payment_id !== null) {
            throw new BusinessRuleException(__('This booking is already assigned.'));
        }

        $transaction->forceFill(['match_status' => MatchStatus::Ignored, 'proposed_invoice_id' => null])->save();
    }

    /**
     * Undo: the payment is removed, the invoice is open again, the booking waits again.
     */
    public function unassign(BankTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $payment = $transaction->payment;
            $transaction->forceFill(['match_status' => MatchStatus::Unmatched, 'payment_id' => null])->save();

            if ($payment !== null) {
                $this->payments->delete($payment);
            }
        });
    }
}
