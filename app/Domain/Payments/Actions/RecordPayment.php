<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Financing\Actions\SyncFinancingPayout;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentDirection;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Payments\Support\Balances;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Vat\Actions\RecordTaxEvents;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Books one payment and allocates it: incoming money to invoices, outgoing money to
 * purchases or costs. An allocation can never exceed what is still open. With the received
 * basis, money received on an invoice creates its tax events.
 */
class RecordPayment
{
    public function __construct(
        private readonly RecordTaxEvents $taxEvents,
        private readonly SyncFinancingPayout $payouts,
    ) {}

    /**
     * @param  array<string, mixed>  $data  direction, paid_on, amount_rp, method, party_id, bank_account_id, reference, notes, bank_transaction_id
     * @param  list<array{0: Model, 1: int}>  $allocations  [record, amount]
     */
    public function __invoke(array $data, array $allocations = []): Payment
    {
        $amount = (int) ($data['amount_rp'] ?? 0);
        $direction = $data['direction'] instanceof PaymentDirection ? $data['direction'] : PaymentDirection::from((string) $data['direction']);

        if ($amount <= 0) {
            throw new BusinessRuleException(__('Enter the amount.'));
        }

        if (array_sum(array_map(fn (array $a): int => $a[1], $allocations)) > $amount) {
            throw new BusinessRuleException(__('More is allocated than the payment amount.'));
        }

        return DB::transaction(function () use ($data, $allocations, $direction): Payment {
            $payment = Payment::create([...$data, 'direction' => $direction]);

            foreach ($allocations as [$record, $allocated]) {
                $this->allocate($payment, $record, $allocated);
            }

            return $payment->load('allocations');
        });
    }

    public function allocate(Payment $payment, Model $record, int $amount): PaymentAllocation
    {
        if ($amount <= 0) {
            throw new BusinessRuleException(__('Enter the amount.'));
        }

        $allowed = $payment->direction === PaymentDirection::In
            ? $record instanceof Invoice
            : $record instanceof Purchase || $record instanceof Cost;

        if (! $allowed) {
            throw new BusinessRuleException(__('Incoming payments are allocated to invoices, outgoing payments to purchases or costs.'));
        }

        if ($record instanceof Invoice && ! $record->status->isIssued()) {
            throw new BusinessRuleException(__('Only issued invoices can be paid.'));
        }

        $open = Balances::openOf($record);

        if ($amount > $open) {
            throw new BusinessRuleException(__('Only :amount is still open.', ['amount' => Money::format($open)]));
        }

        if ($amount > $payment->unallocatedRp()) {
            throw new BusinessRuleException(__('More is allocated than the payment amount.'));
        }

        $allocation = PaymentAllocation::create([
            'payment_id' => $payment->getKey(),
            'allocatable_type' => $record->getMorphClass(),
            'allocatable_id' => $record->getKey(),
            'amount_rp' => $amount,
        ]);

        Balances::refresh($record);
        $this->taxEvents->paymentAllocated($allocation->setRelation('payment', $payment));

        if ($record instanceof Invoice) {
            ($this->payouts)($record);
        }

        return $allocation;
    }

    /**
     * Removes a payment (e.g. booked twice); the invoices and purchases are open again.
     */
    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $allocations = $payment->allocations()->with('allocatable')->get();
            $allocations->each(fn (PaymentAllocation $allocation) => $this->taxEvents->paymentRemoved($allocation));
            $records = $allocations->map->allocatable->filter();
            $payment->allocations()->delete();
            $payment->delete();
            $records->each(function (Model $record): void {
                Balances::refresh($record->refresh());

                if ($record instanceof Invoice) {
                    ($this->payouts)($record);
                }
            });
        });
    }
}
