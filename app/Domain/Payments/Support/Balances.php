<?php

namespace App\Domain\Payments\Support;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Purchasing\Enums\PaymentStatus;
use App\Domain\Purchasing\Models\Purchase;
use Illuminate\Database\Eloquent\Model;

/**
 * Paid and open amounts are always derived from the allocations (and credit notes), never typed.
 */
final class Balances
{
    public static function refresh(Model $record): void
    {
        match (true) {
            $record instanceof Invoice => self::invoice($record),
            $record instanceof Purchase => self::purchase($record),
            default => null,
        };
    }

    public static function invoice(Invoice $invoice): Invoice
    {
        if ($invoice->type === InvoiceType::CreditNote || ! $invoice->status->isIssued()) {
            return $invoice;
        }

        $paid = self::allocated($invoice);
        $credited = -1 * (int) Invoice::query()
            ->where('credits_invoice_id', $invoice->getKey())
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->sum('total_rp');
        $open = $invoice->total_rp - $paid - $credited;

        $status = match (true) {
            $credited >= $invoice->total_rp && $paid === 0 => InvoiceStatus::Cancelled,
            $open <= 0 => InvoiceStatus::Paid,
            $paid + $credited > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Issued,
        };

        $invoice->forceFill(['paid_rp' => $paid, 'credited_rp' => $credited, 'status' => $status])->save();

        return $invoice;
    }

    public static function purchase(Purchase $purchase): Purchase
    {
        $paid = self::allocated($purchase);

        $purchase->forceFill(['payment_status' => match (true) {
            $paid >= $purchase->price_rp => PaymentStatus::Paid,
            $paid > 0 => PaymentStatus::PartiallyPaid,
            default => PaymentStatus::Open,
        }])->save();

        return $purchase;
    }

    public static function openOf(Model $record): int
    {
        return match (true) {
            $record instanceof Invoice => $record->openRp(),
            $record instanceof Purchase => max(0, $record->price_rp - self::allocated($record)),
            default => PHP_INT_MAX,
        };
    }

    private static function allocated(Model $record): int
    {
        return (int) PaymentAllocation::query()
            ->where('allocatable_type', $record->getMorphClass())
            ->where('allocatable_id', $record->getKey())
            ->sum('amount_rp');
    }
}
