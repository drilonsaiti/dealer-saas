<?php

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Parties\Models\Party;
use App\Domain\Payments\Models\BankTransaction;
use App\Domain\Payments\Models\Payment;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vat\Actions\InstallDefaultVatCodes;
use App\Domain\Vat\Models\VatCode;
use App\Filament\App\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Filament\App\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\App\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use Filament\Forms\Components\Repeater;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();
    $this->tenant = makeDealer(['slug' => 'aziri', 'street' => 'Industriestrasse 5', 'zip' => '3052', 'city' => 'Zollikofen', 'vat_number' => 'CHE-404.944.758 MWST']);
    $this->sales = makeMember($this->tenant, Role::Sales);
    $this->accounting = makeMember($this->tenant, Role::Accounting);
    asTenant($this->tenant, fn () => BankAccount::factory()->create(['is_default' => true]));
});

it('invoices a sale from the vehicle file, issues it and books the payment', function () {
    useAppPanel($this->tenant, $this->sales);
    $sale = app(ContractSale::class)(reservedSale()->stockCycle, []);

    Livewire::test(ViewStockCycle::class, ['record' => $sale->stockCycle->getRouteKey()])
        ->assertActionHidden('invoice_deposit')
        ->callAction('invoice_final')
        ->assertHasNoActionErrors();

    $invoice = Invoice::query()->firstOrFail();

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertActionVisible('edit')
        ->callAction('issue')
        ->assertHasNoActionErrors()
        ->assertActionHidden('edit')
        ->assertActionHidden('recordPayment') // sales may issue, accounting books payments
        ->assertActionHidden('creditNote');

    expect($invoice->refresh()->number)->toBe('RE-00001');

    useAppPanel($this->tenant, $this->accounting);
    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->callAction('recordPayment', data: ['paid_on' => '2026-10-09', 'amount_rp' => '27700', 'method' => 'bank'])
        ->assertHasNoActionErrors();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});

it('creates a standard invoice for parts and services', function () {
    useAppPanel($this->tenant, $this->sales);
    Repeater::fake();
    $customer = Party::factory()->create();

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'recipient_party_id' => $customer->id,
            'locale' => 'de',
            'lines' => [
                ['description' => 'Service 30 000 km', 'qty' => 1, 'unit_price_rp' => '480.00', 'vat_code_id' => VatCode::byKey(InstallDefaultVatCodes::TAXABLE_NORMAL)->id],
                ['description' => 'Scheibenwischer', 'qty' => 2, 'unit_price_rp' => '35.50', 'vat_code_id' => VatCode::byKey(InstallDefaultVatCodes::TAXABLE_NORMAL)->id],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->firstOrFail();

    expect($invoice->type)->toBe(InvoiceType::Standard)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->total_rp)->toBe(55_100)
        ->and($invoice->vat_rp)->toBe(4_129);
});

it('imports a bank statement from the bank screen', function () {
    useAppPanel($this->tenant, $this->accounting);
    $account = BankAccount::query()->firstOrFail();
    $xml = '<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.08"><BkToCstmrDbtCdtNtfctn><Ntfctn><Acct><Id><IBAN>'.$account->iban.'</IBAN></Id></Acct>'
        .'<Ntry><Amt Ccy="CHF">10.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-10-08</Dt></BookgDt><AcctSvcrRef>U1</AcctSvcrRef></Ntry></Ntfctn></BkToCstmrDbtCdtNtfctn></Document>';

    Livewire::test(ListBankTransactions::class)
        ->callAction('importStatement', data: [
            'bank_account_id' => $account->id,
            'file' => UploadedFile::fake()->createWithContent('camt054.xml', $xml),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    Livewire::test(ListBankTransactions::class)->assertCountTableRecords(1);
});

it('keeps the bank screen and payments away from sales and read-only users', function () {
    $readOnly = makeMember($this->tenant, Role::ReadOnly);

    asTenant($this->tenant, fn () => expect($this->sales->can('viewAny', BankTransaction::class))->toBeFalse()
        ->and($readOnly->can('create', Invoice::class))->toBeFalse()
        ->and($readOnly->can('viewAny', Invoice::class))->toBeTrue()
        ->and($this->accounting->can('create', Payment::class))->toBeTrue());
});
