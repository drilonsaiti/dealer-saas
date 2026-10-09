<?php

namespace App\Domain\Vat\Actions;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Vat\Enums\TaxEventState;
use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\TaxEvent;
use Illuminate\Support\Facades\DB;

/**
 * "Update": picks up issued invoices and payments that have no tax events yet (e.g. issued
 * before the VAT settings existed) and re-runs the rules for blocked entries whose cause may
 * be fixed now. Entries in closed periods and confirmed entries are never touched.
 */
class CollectTaxEvents
{
    public function __construct(private readonly RecordTaxEvents $record) {}

    public function __invoke(): int
    {
        return DB::transaction(function (): int {
            $this->releaseBlocked();
            $created = 0;

            Invoice::query()
                ->where('status', '!=', InvoiceStatus::Draft->value)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tax_events')
                    ->whereColumn('tax_events.source_id', 'invoices.id')
                    ->where('tax_events.source_type', (new Invoice)->getMorphClass()))
                ->orderBy('issued_on')
                ->each(function (Invoice $invoice) use (&$created): void {
                    $created += $this->record->invoiceIssued($invoice);
                });

            PaymentAllocation::query()
                ->where('allocatable_type', (new Invoice)->getMorphClass())
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tax_events')
                    ->whereColumn('tax_events.source_id', 'payment_allocations.id')
                    ->where('tax_events.source_type', (new PaymentAllocation)->getMorphClass()))
                ->each(function (PaymentAllocation $allocation) use (&$created): void {
                    $created += $this->record->paymentAllocated($allocation);
                });

            return $created;
        });
    }

    /**
     * Sources with a blocked entry are recorded again from scratch, as long as none of their
     * entries is confirmed or sits in a closed period.
     */
    private function releaseBlocked(): void
    {
        $sources = TaxEvent::query()->where('state', TaxEventState::Blocked->value)->get(['source_type', 'source_id'])
            ->unique(fn (TaxEvent $e): string => $e->source_type.'|'.$e->source_id);

        foreach ($sources as $source) {
            $events = TaxEvent::query()->with('period')->where('source_type', $source->source_type)->where('source_id', $source->source_id)->get();
            $locked = $events->contains(fn (TaxEvent $e): bool => $e->confirmed_at !== null || ($e->period !== null && $e->period->status !== VatPeriodStatus::Open));

            if (! $locked) {
                $events->each->delete();
            }
        }
    }
}
