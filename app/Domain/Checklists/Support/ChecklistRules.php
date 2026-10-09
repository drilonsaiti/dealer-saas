<?php

namespace App\Domain\Checklists\Support;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Financing\Models\Financing;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Sales\Models\Sale;
use App\Domain\Vehicles\Enums\Code178Status;
use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The automatic checklist rules: each answers from the records whether an item is done
 * (true / false), or that it does not apply to this sale (null, e.g. leasing items on a
 * cash sale).
 *
 * - commitments.all_done          no open promise blocks the handover
 * - sale.paid                     leasing / credit: payout received; otherwise the invoices are paid
 * - invoice.issued                a final or standard invoice is issued
 * - financing.paid_out            the bank's payout is in
 * - financing.revocation_ended    the customer's revocation period is over
 * - warranty.registered           every warranty sold has its policy number
 * - code178.entered               the bank's code 178 is in the registration document
 * - document:<category>[:signed]  a document of that category is in the file (and signed)
 */
class ChecklistRules
{
    /**
     * @return array<string, string> rule => label, for the template editor
     */
    public static function options(): array
    {
        return [
            'commitments.all_done' => __('Promises to the customer done'),
            'sale.paid' => __('Paid (or leasing paid out)'),
            'invoice.issued' => __('Invoice issued'),
            'financing.paid_out' => __('Leasing paid out'),
            'financing.revocation_ended' => __('Leasing revocation period over'),
            'warranty.registered' => __('Warranty registered'),
            'code178.entered' => __('Code 178 entered'),
            'document:sales_contract:signed' => __('Sales contract signed'),
            'document:leasing_contract' => __('Leasing contract in the file'),
            'document:budget_calculation' => __('Budget calculation in the file'),
            'document:handover_protocol' => __('Handover protocol in the file'),
            'document:warranty_policy' => __('Warranty certificate in the file'),
        ];
    }

    public function evaluate(string $rule, Sale $sale, ?Financing $financing = null): ?bool
    {
        $financing ??= $sale->financing;

        if (str_starts_with($rule, 'document:')) {
            return $this->document($rule, $sale, $financing);
        }

        return match ($rule) {
            'commitments.all_done' => $sale->stockCycle->commitments()->open()->where('blocks_handover', true)->doesntExist(),
            'sale.paid' => $financing !== null ? $financing->status === FinancingStatus::PaidOut : $this->invoicesPaid($sale),
            'invoice.issued' => $this->invoices($sale)->where('status', '!=', InvoiceStatus::Draft->value)->exists(),
            'financing.paid_out' => $financing === null ? null : $financing->status === FinancingStatus::PaidOut,
            'financing.revocation_ended' => $financing === null ? null : ($financing->revocation_until !== null && $financing->revocation_until->lessThan(Carbon::today())),
            'warranty.registered' => $this->warrantiesRegistered($sale),
            'code178.entered' => $financing === null ? null : $sale->stockCycle->vehicle->code178_status === Code178Status::Entered,
            default => false,
        };
    }

    private function invoicesPaid(Sale $sale): bool
    {
        $issued = $this->invoices($sale)->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value])->get();

        return $issued->isNotEmpty() && $issued->every(fn (Invoice $invoice): bool => $invoice->status === InvoiceStatus::Paid);
    }

    /**
     * @return Builder<Invoice>
     */
    private function invoices(Sale $sale): Builder
    {
        return Invoice::query()->where('sale_id', $sale->getKey())->whereIn('type', [InvoiceType::Final->value, InvoiceType::Standard->value]);
    }

    private function warrantiesRegistered(Sale $sale): ?bool
    {
        $warranties = Warranty::query()->where('sale_id', $sale->getKey())->where('status', '!=', WarrantyStatus::Cancelled->value)->get();

        return $warranties->isEmpty() ? null : $warranties->every(fn (Warranty $w): bool => filled($w->policy_number));
    }

    private function document(string $rule, Sale $sale, ?Financing $financing): bool
    {
        [, $category, $state] = array_pad(explode(':', $rule, 3), 3, null);
        $records = array_filter([$sale->stockCycle, $sale, $financing]);

        return Document::query()
            ->whereHas('category', fn (Builder $q) => $q->where('key', $category))
            ->where(function (Builder $q) use ($records): void {
                foreach ($records as $record) {
                    $q->orWhere(fn (Builder $inner) => $inner->linkedTo($record));
                }
            })
            ->when($state === 'signed', fn (Builder $q) => $q->where('status', DocumentStatus::Signed->value))
            ->exists();
    }
}
