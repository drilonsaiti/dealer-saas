<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\AccountingExport;
use App\Domain\Accounting\Models\AccountingExportItem;
use App\Domain\Accounting\Support\AccountChart;
use App\Domain\Accounting\Support\AccountingJournal;
use App\Domain\Accounting\Support\JournalCsv;
use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\Payment;
use App\Domain\Purchasing\Enums\CostStatus;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Tenancy\TenantContext;
use App\Support\BusinessRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Export to accounting": every issued invoice and credit note, payment, vehicle purchase and
 * confirmed cost up to the date that was not exported before → one journal (CSV) in the
 * archive. Records exported once never come again (database unique key), also when two people
 * export at the same moment; late records from earlier months come with the next export.
 */
class CreateAccountingExport
{
    public function __construct(
        private readonly StoreDocument $store,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(Carbon|string $until): AccountingExport
    {
        $until = Carbon::parse($until)->endOfDay();
        $journal = new AccountingJournal(new AccountChart, $this->context->tenant()->default_locale ?? 'de');
        $sources = $this->sources($until);

        $count = array_sum(array_map('count', $sources));

        if ($count === 0) {
            throw new BusinessRuleException(__('There is nothing new to export up to :date.', ['date' => $until->format('d.m.Y')]));
        }

        $entries = [
            ...collect($sources['invoice'])->flatMap(fn (Invoice $r): array => $journal->invoice($r)),
            ...collect($sources['payment'])->flatMap(fn (Payment $r): array => $journal->payment($r)),
            ...collect($sources['purchase'])->flatMap(fn (Purchase $r): array => $journal->purchase($r)),
            ...collect($sources['cost'])->flatMap(fn (Cost $r): array => $journal->cost($r)),
        ];

        usort($entries, fn ($a, $b): int => [$a->date, $a->sourceType, $a->voucher] <=> [$b->date, $b->sourceType, $b->voucher]);

        try {
            return DB::transaction(function () use ($until, $sources, $count, $entries): AccountingExport {
                $export = new AccountingExport(['number' => $this->nextNumber($until), 'until_on' => $until->toDateString()]);
                $export->forceFill([
                    'records_count' => $count,
                    'entries_count' => count($entries),
                    'total_rp' => array_sum(array_map(fn ($e): int => $e->amountRp, $entries)),
                ])->save();

                foreach ($sources as $type => $records) {
                    foreach ($records as $record) {
                        AccountingExportItem::query()->create(['accounting_export_id' => $export->getKey(), 'source_type' => $type, 'source_id' => $record->getKey()]);
                    }
                }

                $document = $this->file($export, JournalCsv::render($entries, $export->number, $this->context->tenant()->default_locale ?? 'de'));
                $export->forceFill(['document_id' => $document->getKey()])->save();

                return $export;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException(__('Someone exported at the same moment. Please try again.'));
        }
    }

    /**
     * How many records are waiting (for the screen).
     *
     * @return array<string, int>
     */
    public function pending(Carbon|string $until): array
    {
        $until = Carbon::parse($until)->endOfDay();

        return array_map('count', $this->sources($until));
    }

    /**
     * @return array{invoice: list<Invoice>, payment: list<Payment>, purchase: list<Purchase>, cost: list<Cost>}
     */
    private function sources(Carbon $until): array
    {
        $notExported = fn (string $type) => fn (Builder $query) => $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')
            ->from('accounting_export_items')
            ->where('accounting_export_items.source_type', $type)
            ->whereColumn('accounting_export_items.source_id', $query->getModel()->getQualifiedKeyName()));

        $invoices = Invoice::query()->whereNotNull('number')->whereNotNull('issued_on')->where('status', '!=', InvoiceStatus::Draft->value)
            ->where('issued_on', '<=', $until->toDateString())->tap($notExported('invoice'))
            ->with(['lines', 'recipient', 'stockCycle'])->orderBy('issued_on')->orderBy('number')->get();

        $payments = Payment::query()->where('paid_on', '<=', $until->toDateString())->tap($notExported('payment'))
            ->with('party')->orderBy('paid_on')->get();

        $purchases = Purchase::query()->where('contract_on', '<=', $until->toDateString())->tap($notExported('purchase'))
            ->with(['stockCycle.vehicle', 'seller'])->orderBy('contract_on')->get();

        $costs = Cost::query()->where('status', CostStatus::Confirmed->value)->where('is_estimate', false)
            ->where('incurred_on', '<=', $until->toDateString())->tap($notExported('cost'))
            ->with(['category', 'stockCycle', 'supplier'])->orderBy('incurred_on')->get();

        return [
            'invoice' => $invoices->values()->all(),
            'payment' => $payments->values()->all(),
            'purchase' => $purchases->values()->all(),
            'cost' => $costs->values()->all(),
        ];
    }

    private function nextNumber(Carbon $until): string
    {
        $year = $until->year;
        $last = AccountingExport::query()->where('number', 'like', "{$year}-%")->lockForUpdate()->pluck('number')
            ->map(fn (string $n): int => (int) substr($n, 5))->max() ?? 0;

        return sprintf('%d-%03d', $year, $last + 1);
    }

    private function file(AccountingExport $export, string $csv): Document
    {
        $category = DocumentCategory::query()->where('key', 'accounting_export')->first();

        if ($category === null) {
            app(InstallDefaultDocumentCategories::class)();
            $category = DocumentCategory::query()->where('key', 'accounting_export')->firstOrFail();
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'journal');
        file_put_contents($path, $csv);

        try {
            $document = ($this->store)($path, $category, [
                'title' => __('Accounting export :number', ['number' => $export->number]),
                'document_on' => $export->until_on->toDateString(),
                'source' => DocumentSource::Generated,
                'original_name' => 'buchhaltung-'.$export->number.'.csv',
            ], [$export]);
        } finally {
            @unlink($path);
        }

        $document->currentVersion?->forceFill(['locked_at' => now()])->save();

        return $document;
    }
}
