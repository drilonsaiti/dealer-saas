<?php

namespace App\Domain\Vat\Actions;

use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Domain\Payments\Enums\PaymentDirection;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vat\Enums\TaxEventState;
use App\Domain\Vat\Enums\VatBasis;
use App\Domain\Vat\Enums\VatCodeKind;
use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\TaxEvent;
use App\Domain\Vat\Models\VatProfile;
use App\Domain\Vat\Rules\InvoiceLineRule;
use App\Domain\Vat\Support\VatPeriods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Creates the tax events of a business event through the versioned rules. Only issued
 * invoices and booked payments count (never drafts, offers or contracts: the invoice leads).
 *
 * - Agreed basis (default): the issued invoice, on its date.
 * - Received basis: each payment received, on the payment date, split over the invoice lines.
 *   Credit notes count on their date under both bases.
 *
 * Running it again for the same source does nothing (no double counting).
 */
class RecordTaxEvents
{
    public function __construct(
        private readonly InvoiceLineRule $rule,
        private readonly VatPeriods $periods,
        private readonly TenantContext $context,
    ) {}

    public function invoiceIssued(Invoice $invoice): int
    {
        if (! $invoice->status->isIssued() || $invoice->issued_on === null) {
            return 0;
        }

        $on = $invoice->issued_on->copy();
        $profile = VatProfile::validOn($on);

        if ($profile !== null && $profile->basis === VatBasis::Received && $invoice->type !== InvoiceType::CreditNote) {
            return 0;
        }

        $invoice->loadMissing('lines.vatCode');
        $amounts = $invoice->lines->mapWithKeys(fn (InvoiceLine $line): array => [$line->getKey() => $line->total_rp])->all();

        return $this->record($invoice, $invoice, $amounts, $on, $profile);
    }

    public function paymentAllocated(PaymentAllocation $allocation): int
    {
        $allocation->loadMissing(['payment', 'allocatable']);
        $invoice = $allocation->allocatable;

        if (! $invoice instanceof Invoice || $allocation->payment->direction !== PaymentDirection::In) {
            return 0;
        }

        $on = $allocation->payment->paid_on->copy();
        $profile = VatProfile::validOn($on);

        if ($profile === null || $profile->basis !== VatBasis::Received || $invoice->total_rp === 0) {
            return 0;
        }

        $invoice->loadMissing('lines.vatCode');

        return $this->record($allocation, $invoice, $this->split($invoice, $allocation->amount_rp), $on, $profile);
    }

    /**
     * A payment was removed: its events disappear while their period is open, otherwise
     * they are reversed in the period of today.
     */
    public function paymentRemoved(PaymentAllocation $allocation): void
    {
        $events = TaxEvent::query()->with('period')
            ->where('source_type', $allocation->getMorphClass())
            ->where('source_id', $allocation->getKey())
            ->get();

        foreach ($events as $event) {
            if ($event->period === null || $event->period->status === VatPeriodStatus::Open) {
                $event->delete();

                continue;
            }

            $today = Carbon::today();
            $profile = VatProfile::validOn($today) ?? $event->period->profile;
            $period = $this->periods->for($today, $profile);
            $explanation = $event->explanation;
            $explanation['steps'][] = InvoiceLineRule::step('Payment removed: this reverses the entry of :date.', ['date' => $event->event_on->format('d.m.Y')]);

            TaxEvent::create([
                ...$event->only(['invoice_id', 'vat_code_id', 'kind', 'field', 'legal_rate', 'net_tax_rate_id', 'net_rate', 'state', 'rule_key', 'rule_version']),
                'source_type' => 'payment_reversal',
                'source_id' => $allocation->getKey(),
                'event_on' => $today->toDateString(),
                'base_rp' => -$event->base_rp,
                'tax_rp' => -$event->tax_rp,
                'period_id' => $period->getKey(),
                'late' => $period->corrects_period_id !== null,
                'explanation' => $explanation,
            ]);
        }
    }

    /**
     * @param  array<string, int>  $amounts  line id => gross amount
     */
    private function record(Model $source, Invoice $invoice, array $amounts, Carbon $on, ?VatProfile $profile): int
    {
        if (TaxEvent::query()->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->exists()) {
            return 0;
        }

        $base = ['source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'event_on' => $on->toDateString()];

        if ($profile === null) {
            if (blank($this->context->tenant()?->vat_number)) {
                return 0; // not registered for VAT, nothing to report
            }

            $reason = 'No VAT settings are valid on this date. Set them up (Settings → VAT), then update the period.';
            TaxEvent::create([...$base,
                'invoice_id' => $invoice->getKey(), 'kind' => VatCodeKind::Taxable, 'field' => '200', 'base_rp' => array_sum($amounts),
                'state' => TaxEventState::Blocked, 'rule_key' => InvoiceLineRule::KEY, 'rule_version' => InvoiceLineRule::VERSION,
                'explanation' => ['steps' => [InvoiceLineRule::step(':invoice of :date', ['invoice' => (string) $invoice->number, 'date' => $on->format('d.m.Y')]), InvoiceLineRule::step($reason)], 'missing' => $reason],
            ]);

            return 1;
        }

        $created = 0;

        foreach ($invoice->lines as $line) {
            $attributes = $this->rule->apply($line, $invoice, $amounts[$line->getKey()] ?? 0, $profile);

            if ($attributes === null) {
                continue;
            }

            $period = $this->periods->for($on, $profile);
            TaxEvent::create([...$base, ...$attributes, 'period_id' => $period->getKey(), 'late' => $period->corrects_period_id !== null]);
            $created++;
        }

        return $created;
    }

    /**
     * A payment's share per line, proportional to the line amounts; the rounding rest goes
     * to the last line.
     *
     * @return array<string, int>
     */
    private function split(Invoice $invoice, int $paidRp): array
    {
        $lines = $invoice->lines->filter(fn (InvoiceLine $line): bool => $line->total_rp !== 0)->values();
        $shares = [];
        $rest = $paidRp;

        foreach ($lines as $i => $line) {
            $share = $i === $lines->count() - 1 ? $rest : (int) round($paidRp * $line->total_rp / $invoice->total_rp);
            $shares[$line->getKey()] = $share;
            $rest -= $share;
        }

        return $shares;
    }
}
