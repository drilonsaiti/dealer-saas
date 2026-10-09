<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceLineKind;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Corrects an issued invoice with a credit note (issued right away): the whole invoice, or
 * one amount. The invoice itself never changes.
 */
class IssueCreditNote
{
    public function __construct(
        private readonly SaveInvoiceDraft $save,
        private readonly IssueInvoice $issue,
    ) {}

    public function __invoke(Invoice $invoice, string $reason, ?int $amountRp = null): Invoice
    {
        if (! $invoice->status->isIssued() || $invoice->type === InvoiceType::CreditNote) {
            throw new BusinessRuleException(__('Only an issued invoice can be credited.'));
        }

        if ($invoice->status === InvoiceStatus::Cancelled) {
            throw new BusinessRuleException(__('This invoice is already fully credited.'));
        }

        if (trim($reason) === '') {
            throw new BusinessRuleException(__('Please give a reason.'));
        }

        $creditable = $invoice->total_rp - $invoice->credited_rp;

        if ($amountRp !== null && ($amountRp <= 0 || $amountRp > $creditable)) {
            throw new BusinessRuleException(__('At most :amount can be credited.', ['amount' => Money::format($creditable)]));
        }

        $invoice->loadMissing('lines');
        $locale = $invoice->locale;

        $lines = $amountRp === null && $invoice->credited_rp === 0
            ? $invoice->lines->map(fn (InvoiceLine $line): array => [
                'kind' => $line->kind->value,
                'description' => $line->description,
                'qty' => (float) $line->qty,
                'unit_price_rp' => -$line->unit_price_rp,
                'vat_code_id' => $line->vat_code_id,
            ])->all()
            : [[
                'kind' => InvoiceLineKind::Other->value,
                'description' => __('Credit for invoice :number: :reason', ['number' => $invoice->number, 'reason' => $reason], $locale),
                'unit_price_rp' => -($amountRp ?? $creditable),
                'vat_code_id' => $invoice->lines->first(fn (InvoiceLine $l): bool => $l->vat_code_id !== null)?->vat_code_id,
            ]];

        return DB::transaction(function () use ($invoice, $lines, $reason): Invoice {
            $draft = ($this->save)(null, [
                'type' => InvoiceType::CreditNote,
                'credits_invoice_id' => $invoice->getKey(),
                'sale_id' => $invoice->sale_id,
                'stock_cycle_id' => $invoice->stock_cycle_id,
                'recipient_party_id' => $invoice->recipient_party_id,
                'locale' => $invoice->locale,
                'service_on' => $invoice->service_on?->toDateString(),
                'notes' => $reason,
            ], array_values($lines));

            return ($this->issue)($draft);
        });
    }
}
