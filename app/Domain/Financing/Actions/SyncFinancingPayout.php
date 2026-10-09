<?php

namespace App\Domain\Financing\Actions;

use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Financing\Models\Financing;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Sales\Models\Sale;

/**
 * The bank's invoice is paid (usually from the bank import): the financing is "paid out"
 * with the payment date. If that payment is removed, it goes back to "documents sent".
 */
class SyncFinancingPayout
{
    public function __invoke(Invoice $invoice): void
    {
        $sale = $invoice->sale_id === null ? null : Sale::query()->find($invoice->sale_id);
        $financing = $sale?->financing;

        if (! $financing instanceof Financing || $invoice->recipient_party_id !== $financing->partner_party_id) {
            return;
        }

        $invoice->refresh();

        if ($invoice->status === InvoiceStatus::Paid && $financing->status !== FinancingStatus::PaidOut) {
            $lastPayment = PaymentAllocation::query()->with('payment')
                ->where('allocatable_type', $invoice->getMorphClass())->where('allocatable_id', $invoice->getKey())
                ->get()->map(fn (PaymentAllocation $a) => $a->payment->paid_on)->max();

            $financing->forceFill(['status' => FinancingStatus::PaidOut, 'payout_received_on' => $lastPayment?->toDateString()])->save();
        } elseif ($invoice->status !== InvoiceStatus::Paid && $financing->status === FinancingStatus::PaidOut) {
            $financing->forceFill(['status' => FinancingStatus::DocumentsSent, 'payout_received_on' => null])->save();
        }
    }
}
