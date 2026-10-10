<?php

namespace App\Domain\Accounting\Support;

use App\Domain\Invoicing\Enums\InvoiceLineKind;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentDirection;
use App\Domain\Payments\Models\Payment;
use App\Domain\Purchasing\Enums\VatSituation;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Vat\Enums\VatMethod;
use App\Domain\Vat\Models\VatProfile;
use DateTimeInterface;

/**
 * Turns invoices, payments, purchases and confirmed costs into double-entry bookings.
 *
 * - Invoices: receivables to revenue per line (vehicle → vehicle sales, items → services,
 *   deposits → customer deposits), VAT to "VAT due". Credit notes and negative lines the other way.
 * - Payments in: money account to receivables; payments out: payables to money account.
 * - Purchases and costs: expense to payables; input VAT separately when the dealer uses the
 *   effective method and the seller showed VAT.
 * - With the net tax rate method (Saldosteuersatz) or without VAT liability, revenue is booked
 *   gross; the tax owed is booked by the accountant from the VAT return.
 */
class AccountingJournal
{
    /** @var array<string, VatProfile|null> */
    private array $profiles = [];

    public function __construct(private readonly AccountChart $chart, private readonly string $locale = 'de') {}

    /**
     * @return list<JournalEntry>
     */
    public function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'recipient', 'stockCycle']);
        $date = ($invoice->issued_on ?? $invoice->created_at)->toDateString();
        $splitVat = $this->splitsOutputVat($invoice->issued_on ?? $invoice->created_at);
        $label = $invoice->type === InvoiceType::CreditNote ? __('Credit note', [], $this->locale) : __('Invoice', [], $this->locale);
        $text = trim($label.' '.$invoice->number.' · '.$invoice->recipient->displayName());
        $receivables = $this->chart->account('receivables');
        $entries = [];

        foreach ($invoice->lines as $line) {
            $revenue = $this->chart->account(match ($line->kind) {
                InvoiceLineKind::Vehicle => 'vehicle_sales',
                InvoiceLineKind::Deposit, InvoiceLineKind::DepositDeduction, InvoiceLineKind::CollectionCredit => 'customer_deposits',
                default => 'services',
            });

            $net = $splitVat ? $line->net_rp : $line->total_rp;
            $rate = $line->vat_code_id !== null ? rtrim(rtrim(number_format((float) $line->vat_rate, 3, '.', ''), '0'), '.') : null;

            $entries[] = $this->pair($date, (string) $invoice->number, $receivables, $revenue, $net, $rate, $text.' · '.$line->description, $invoice, 'invoice');

            if ($splitVat && $line->vat_rp !== 0) {
                $entries[] = $this->pair($date, (string) $invoice->number, $receivables, $this->chart->account('vat_due'), $line->vat_rp, $rate, $text.' · '.__('VAT', [], $this->locale).' '.$rate.' %', $invoice, 'invoice');
            }
        }

        // The invoice total is binding; a rounding difference to the lines goes to services.
        $difference = $invoice->total_rp - (int) $invoice->lines->sum('total_rp');
        $entries[] = $this->pair($date, (string) $invoice->number, $receivables, $this->chart->account('services'), $difference, null, $text.' · '.__('Rounding', [], $this->locale), $invoice, 'invoice');

        return array_values(array_filter($entries));
    }

    /**
     * @return list<JournalEntry>
     */
    public function payment(Payment $payment): array
    {
        $payment->loadMissing('party');
        $money = $this->chart->moneyAccount($payment->method, $payment->bank_account_id);
        $text = trim($payment->method->getLabel().' · '.($payment->party?->displayName() ?? '').' '.($payment->reference ?? ''));
        $voucher = $payment->reference ?? mb_substr($payment->getKey(), 0, 8);

        $entry = $payment->direction === PaymentDirection::In
            ? $this->pair($payment->paid_on->toDateString(), $voucher, $money, $this->chart->account('receivables'), $payment->amount_rp, null, $text, $payment, 'payment')
            : $this->pair($payment->paid_on->toDateString(), $voucher, $this->chart->account('payables'), $money, $payment->amount_rp, null, $text, $payment, 'payment');

        return $entry === null ? [] : [$entry];
    }

    /**
     * @return list<JournalEntry>
     */
    public function purchase(Purchase $purchase): array
    {
        $purchase->loadMissing(['stockCycle.vehicle', 'seller']);
        $vehicle = $purchase->stockCycle->vehicle;
        $text = trim(__('Vehicle purchase', [], $this->locale).' '.$vehicle->displayName().' · '.($purchase->seller?->displayName() ?? ''));
        $inputVat = $purchase->vat_situation === VatSituation::CompanyVatShown && ($purchase->vat_shown_rp ?? 0) > 0 && $this->splitsInputVat($purchase->contract_on)
            ? (int) $purchase->vat_shown_rp
            : 0;

        return $this->expense($purchase->contract_on->toDateString(), $purchase->stockCycle->number ?? '', $this->chart->account('vehicle_purchases'), $purchase->price_rp, $inputVat, $text, $purchase, 'purchase', $purchase->stockCycle->number);
    }

    /**
     * @return list<JournalEntry>
     */
    public function cost(Cost $cost): array
    {
        $cost->loadMissing(['category', 'stockCycle', 'supplier']);
        $text = trim($cost->category->getTranslation('name', $this->locale).' · '.($cost->description ?? '').' '.($cost->supplier !== null ? '· '.$cost->supplier->displayName() : ''));
        $inputVat = ($cost->vat_rp ?? 0) > 0 && $this->splitsInputVat($cost->incurred_on) ? (int) $cost->vat_rp : 0;

        return $this->expense($cost->incurred_on->toDateString(), $cost->stockCycle->number ?? '', $this->chart->account('cost:'.$cost->category->key), $cost->gross_rp, $inputVat, $text, $cost, 'cost', $cost->stockCycle?->number);
    }

    /**
     * @return list<JournalEntry>
     */
    private function expense(string $date, string $voucher, string $account, int $grossRp, int $inputVatRp, string $text, Purchase|Cost $source, string $type, ?string $fileNumber): array
    {
        $payables = $this->chart->account('payables');
        $entries = [
            $this->pair($date, $voucher, $account, $payables, $grossRp - $inputVatRp, null, $text, $source, $type, $fileNumber),
        ];

        if ($inputVatRp > 0) {
            $entries[] = $this->pair($date, $voucher, $this->chart->account('input_vat'), $payables, $inputVatRp, null, $text.' · '.__('Input VAT', [], $this->locale), $source, $type, $fileNumber);
        }

        return array_values(array_filter($entries));
    }

    /**
     * A booking; a negative amount swaps debit and credit. Zero amounts are dropped.
     */
    private function pair(string $date, string $voucher, string $debit, string $credit, int $amountRp, ?string $rate, string $text, Invoice|Payment|Purchase|Cost $source, string $type, ?string $fileNumber = null): ?JournalEntry
    {
        if ($amountRp === 0) {
            return null;
        }

        if ($amountRp < 0) {
            [$debit, $credit, $amountRp] = [$credit, $debit, -$amountRp];
        }

        if ($fileNumber === null && $source instanceof Invoice) {
            $fileNumber = $source->stockCycle?->number;
        }

        return new JournalEntry($date, $voucher, $debit, $credit, $amountRp, $rate, mb_substr(trim($text, ' ·'), 0, 250), $fileNumber, $type, $source->getKey());
    }

    private function profile(DateTimeInterface $date): ?VatProfile
    {
        $key = $date->format('Y-m-d');

        return array_key_exists($key, $this->profiles) ? $this->profiles[$key] : $this->profiles[$key] = VatProfile::validOn($date);
    }

    private function splitsOutputVat(DateTimeInterface $date): bool
    {
        $profile = $this->profile($date);

        return $profile !== null && $profile->liable && $profile->method === VatMethod::Effective;
    }

    private function splitsInputVat(DateTimeInterface $date): bool
    {
        return $this->splitsOutputVat($date);
    }
}
