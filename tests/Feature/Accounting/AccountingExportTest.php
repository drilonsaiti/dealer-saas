<?php

use App\Domain\Accounting\Actions\CancelAccountingExport;
use App\Domain\Accounting\Actions\CreateAccountingExport;
use App\Domain\Accounting\Actions\SaveAccountMappings;
use App\Domain\Accounting\Models\AccountingExport;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Actions\SaveInvoiceDraft;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Parties\Models\Party;
use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Purchasing\Actions\ConfirmCost;
use App\Domain\Purchasing\Actions\RecordCost;
use App\Domain\Purchasing\Actions\RecordPurchase;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vat\Actions\InstallDefaultVatCodes;
use App\Domain\Vat\Actions\SaveVatProfile;
use App\Domain\Vat\Models\VatCode;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\AccountingExports\Pages\ManageAccountingExports;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Accounting export (Phase 3): every invoice, payment, purchase and confirmed cost goes to the
 * accountant once, as balanced double-entry bookings with the dealer's account numbers.
 */

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();
    Carbon::setTestNow('2026-04-02 10:00');
    $this->tenant = makeDealer(['slug' => 'aziri']);
    $this->user = makeMember($this->tenant, Role::Accounting);
    $this->actingAs($this->user);
    $this->bank = asTenant($this->tenant, fn () => BankAccount::factory()->create(['iban' => 'CH5604835012345678009', 'qr_iban' => null, 'is_default' => true]));
});

afterEach(fn () => Carbon::setTestNow());

function vatMethod(string $method): void
{
    app(SaveVatProfile::class)(null, ['valid_from' => '2026-01-01', 'liable' => true, 'vat_number' => 'CHE-404.944.758 MWST', 'method' => $method, 'basis' => 'agreed', 'period' => $method === 'effective' ? 'quarter' : 'half_year'],
        $method === 'effective' ? [] : [['activity' => 'Autohandel', 'activity_code' => '11111', 'rate' => '0.6']]);
}

/** A standard invoice: one vehicle line and one service line, incl. 8.1 % VAT. */
function bookingInvoice(string $on = '2026-03-10'): Invoice
{
    Carbon::setTestNow($on.' 10:00');
    $code = VatCode::byKey(InstallDefaultVatCodes::TAXABLE_NORMAL)->id;
    $draft = app(SaveInvoiceDraft::class)(null, ['type' => InvoiceType::Standard, 'recipient_party_id' => Party::factory()->create(['last_name' => 'Meier'])->id], [
        ['kind' => 'vehicle', 'description' => 'VW Golf', 'unit_price_rp' => 2_162_000, 'vat_code_id' => $code],
        ['kind' => 'item', 'description' => 'Vignette', 'unit_price_rp' => 4_000, 'vat_code_id' => $code],
    ]);
    $invoice = app(IssueInvoice::class)($draft);
    Carbon::setTestNow('2026-04-02 10:00');

    return $invoice;
}

/**
 * @return list<array<string, string>>
 */
function journalRows(AccountingExport $export): array
{
    $version = $export->document->currentVersion;
    $csv = Storage::disk($version->disk)->get($version->path);
    expect(str_starts_with($csv, "\u{FEFF}"))->toBeTrue();

    $lines = array_map(fn (string $l): array => str_getcsv($l, ';', '"', ''), array_values(array_filter(explode("\n", substr($csv, 3)))));
    array_shift($lines); // header in the dealer's language
    $keys = ['Date', 'Voucher', 'Debit', 'Credit', 'Amount', 'VAT rate', 'Text', 'Vehicle file', 'Source', 'Source ID', 'Export'];

    return array_map(fn (array $l): array => array_combine($keys, $l), $lines);
}

/**
 * @param  list<array<string, string>>  $rows
 * @return array<string, int> account => balance in Rappen (debit positive)
 */
function balances(array $rows): array
{
    $balance = [];

    foreach ($rows as $row) {
        $amount = (int) round(((float) $row['Amount']) * 100);
        $balance[$row['Debit']] = ($balance[$row['Debit']] ?? 0) + $amount;
        $balance[$row['Credit']] = ($balance[$row['Credit']] ?? 0) - $amount;
    }

    ksort($balance);

    return $balance;
}

it('exports invoices, payments, purchases and costs as balanced bookings with VAT split (effective method)', function () {
    $export = asTenant($this->tenant, function () {
        vatMethod('effective');
        $invoice = bookingInvoice();
        app(RecordPayment::class)(['direction' => 'in', 'paid_on' => '2026-03-20', 'amount_rp' => 1_000_000, 'method' => 'bank', 'bank_account_id' => $this->bank->id, 'reference' => 'E2E-1'], [[$invoice, 1_000_000]]);
        app(RecordPayment::class)(['direction' => 'in', 'paid_on' => '2026-03-21', 'amount_rp' => 50_000, 'method' => 'cash'], [[$invoice->refresh(), 50_000]]);

        $cycle = StockCycle::factory()->create();
        $purchase = app(RecordPurchase::class)($cycle, ['seller_party_id' => Party::factory()->create()->id, 'seller_kind' => 'company', 'contract_on' => '2026-03-01', 'price_rp' => 1_081_000, 'vat_situation' => 'company_vat_shown', 'vat_shown_rp' => 81_000]);
        app(RecordPayment::class)(['direction' => 'out', 'paid_on' => '2026-03-05', 'amount_rp' => 1_081_000, 'method' => 'bank'], [[$purchase, 1_081_000]]);
        $repair = CostCategory::query()->where('key', 'repair')->sole();
        app(ConfirmCost::class)(app(RecordCost::class)(['stock_cycle_id' => $cycle->id, 'category_id' => $repair->id, 'incurred_on' => '2026-03-12', 'gross_rp' => 108_100, 'vat_rp' => 8_100, 'description' => 'Garage Muster']));
        app(RecordCost::class)(['stock_cycle_id' => $cycle->id, 'category_id' => $repair->id, 'incurred_on' => '2026-03-13', 'gross_rp' => 50_000, 'description' => 'Not confirmed yet']);

        return app(CreateAccountingExport::class)('2026-03-31');
    });

    expect($export->number)->toBe('2026-001')
        ->and($export->records_count)->toBe(6); // invoice, 3 payments, purchase, confirmed cost

    $rows = asTenant($this->tenant, fn () => journalRows($export->refresh()));
    $balances = balances($rows);

    // Invoice 21'660.00: 20'000.00 + 1'620.00 VAT (vehicle) and 37.00 + 3.00 VAT (vignette).
    expect($balances)->toMatchArray([
        '1000' => 50_000,                          // cash in
        '1020' => 1_000_000 - 1_081_000,            // bank in − bank out
        '1100' => 2_166_000 - 1_050_000,            // receivable less payments
        '1170' => 81_000 + 8_100,                   // input VAT purchase + cost
        '2000' => -108_100,                         // purchase paid; the cost is still open
        '2200' => -(162_000 + 300),                 // VAT due on both lines
        '3200' => -2_000_000,
        '3400' => -3_700,
        '4200' => 1_000_000,
        '4400' => 100_000,
    ])
        ->and(array_sum($balances))->toBe(0);

    $golf = collect($rows)->firstWhere('Credit', '3200');
    expect($golf['Date'])->toBe('10.03.2026')
        ->and($golf['Voucher'])->toStartWith('RE-')
        ->and($golf['VAT rate'])->toBe('8.1')
        ->and($golf['Text'])->toContain('Meier')->toContain('VW Golf')
        ->and($golf['Source'])->toBe('invoice');
});

it('never exports a record twice, picks up late records and can undo the latest export', function () {
    asTenant($this->tenant, function () {
        vatMethod('net_tax_rate');
        bookingInvoice('2026-02-10');
        $first = app(CreateAccountingExport::class)('2026-02-28');

        expect(fn () => app(CreateAccountingExport::class)('2026-02-28'))->toThrow(BusinessRuleException::class, 'nothing new');

        // Entered late (dated February) and a March invoice: both come with the next export.
        bookingInvoice('2026-02-20');
        bookingInvoice('2026-03-05');
        $second = app(CreateAccountingExport::class)('2026-03-31');
        expect($second->records_count)->toBe(2)->and($second->number)->toBe('2026-002');

        expect(fn () => app(CancelAccountingExport::class)($first))->toThrow(BusinessRuleException::class, 'latest');

        app(CancelAccountingExport::class)($second);
        $third = app(CreateAccountingExport::class)('2026-03-31');
        expect($third->records_count)->toBe(2)->and($third->number)->toBe('2026-003')
            ->and($second->refresh()->cancelled_at)->not->toBeNull()
            ->and($second->document_id)->not->toBeNull();
    });
});

it('books revenue gross with the net tax rate method and reverses credit notes', function () {
    $export = asTenant($this->tenant, function () {
        vatMethod('net_tax_rate');
        $invoice = bookingInvoice();
        app(IssueCreditNote::class)($invoice, 'Rabatt', 16_600);

        return app(CreateAccountingExport::class)('2026-04-02');
    });

    $balances = asTenant($this->tenant, fn () => balances(journalRows($export->refresh())));

    expect($balances)->not->toHaveKey('2200')
        ->and($balances['3200'])->toBe(-2_162_000)
        ->and($balances['3400'])->toBe(-4_000 + 16_600) // credit note (one line "other") reduces services
        ->and($balances['1100'])->toBe(2_166_000 - 16_600)
        ->and(array_sum($balances))->toBe(0);
});

it('uses the dealer\'s own account numbers, also per bank account and cost category', function () {
    $export = asTenant($this->tenant, function () {
        vatMethod('net_tax_rate');
        app(SaveAccountMappings::class)(['vehicle_sales' => '3000', 'bank:'.$this->bank->id => '1021', 'cost:repair' => '4410', 'receivables' => '1100']);
        $invoice = bookingInvoice();
        app(RecordPayment::class)(['direction' => 'in', 'paid_on' => '2026-03-20', 'amount_rp' => 2_166_000, 'method' => 'bank', 'bank_account_id' => $this->bank->id], [[$invoice, 2_166_000]]);
        $repair = CostCategory::query()->where('key', 'repair')->sole();
        app(ConfirmCost::class)(app(RecordCost::class)(['category_id' => $repair->id, 'incurred_on' => '2026-03-12', 'gross_rp' => 20_000, 'description' => '=HYPERLINK("x")']));

        expect(fn () => app(SaveAccountMappings::class)(['cash' => '10 00; DROP']))->toThrow(BusinessRuleException::class);

        return app(CreateAccountingExport::class)('2026-03-31');
    });

    $rows = asTenant($this->tenant, fn () => journalRows($export->refresh()));
    $balances = balances($rows);

    expect($balances)->toHaveKeys(['3000', '1021', '4410'])->not->toHaveKey('3200')
        ->and(collect($rows)->firstWhere('Debit', '4410')['Text'])->not->toStartWith('='); // no spreadsheet formulas
});

it('creates an export and edits the accounts on the finance screen', function () {
    useAppPanel($this->tenant, $this->user);
    vatMethod('net_tax_rate');
    bookingInvoice();

    Livewire::test(ManageAccountingExports::class)
        ->callAction('accounts', data: ['accounts' => ['vehicle_sales' => '3010']])
        ->assertHasNoActionErrors()
        ->callAction('export', data: ['until' => '2026-03-31'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $export = AccountingExport::query()->sole();

    Livewire::test(ManageAccountingExports::class)
        ->assertCanSeeTableRecords([$export])
        ->callTableAction('download', $export)
        ->assertFileDownloaded('buchhaltung-2026-001.csv');

    expect(collect(journalRows($export))->pluck('Credit'))->toContain('3010');
});

it('keeps the accounting export to accounting and administrators', function () {
    $sales = makeMember($this->tenant, Role::Sales);

    asTenant($this->tenant, fn () => expect($sales->can('viewAny', AccountingExport::class))->toBeFalse()
        ->and($this->user->can('create', AccountingExport::class))->toBeTrue());
});
