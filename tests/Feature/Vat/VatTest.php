<?php

use App\Domain\Documents\Actions\GenerateContract;
use App\Domain\Invoicing\Actions\CreateInvoiceFromSale;
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
use App\Domain\Reporting\CalculateMargin;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Sales\Models\Sale;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vat\Actions\CloseVatPeriod;
use App\Domain\Vat\Actions\CollectTaxEvents;
use App\Domain\Vat\Actions\ConfirmTaxEvent;
use App\Domain\Vat\Actions\ExportVatPeriod;
use App\Domain\Vat\Actions\InstallDefaultVatCodes;
use App\Domain\Vat\Actions\RecordTaxEvents;
use App\Domain\Vat\Actions\RecordVatSubmission;
use App\Domain\Vat\Actions\SaveVatProfile;
use App\Domain\Vat\Enums\TaxEventState;
use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\TaxEvent;
use App\Domain\Vat\Models\VatCode;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Models\VatProfile;
use App\Domain\Vat\Support\PeriodCalculator;
use App\Domain\Vat\Support\VatPeriods;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
 * VAT acceptance cases of Phase 2 (VAT requirements chapter 12) with Aziri's set-up: net tax
 * rate method, 0.6 % for car trade, agreed consideration, half-yearly returns.
 */

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();
    config(['dealer.vat.ech0217_xsd' => null]); // the real schema is checked in Ech0217StructureTest
    Carbon::setTestNow('2026-03-16 10:00');

    $this->tenant = makeDealer(['name' => 'Aziri Automobile GmbH', 'legal_name' => 'Aziri Automobile GmbH', 'slug' => 'aziri', 'street' => 'Industriestrasse 5', 'zip' => '3052', 'city' => 'Zollikofen', 'uid' => 'CHE-404.944.758', 'vat_number' => 'CHE-404.944.758 MWST']);
    $this->user = makeMember($this->tenant, Role::Accounting);
    $this->actingAs($this->user);
    asTenant($this->tenant, fn () => BankAccount::factory()->create(['iban' => 'CH5604835012345678009', 'qr_iban' => null, 'is_default' => true]));
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<array<string, mixed>>  $rates
 */
function aziriVatProfile(array $attributes = [], array $rates = [['activity' => 'Autohandel', 'activity_code' => '11111', 'rate' => '0.6']]): VatProfile
{
    return app(SaveVatProfile::class)(null, [
        'valid_from' => '2026-01-01',
        'liable' => true,
        'vat_number' => 'CHE-404.944.758 MWST',
        'method' => 'net_tax_rate',
        'basis' => 'agreed',
        'period' => 'half_year',
        ...$attributes,
    ], $rates);
}

/** An issued standard invoice with one line (gross, VAT included). */
function issuedInvoice(int $grossRp, string $codeKey = InstallDefaultVatCodes::TAXABLE_NORMAL): Invoice
{
    $draft = app(SaveInvoiceDraft::class)(null, ['type' => InvoiceType::Standard, 'recipient_party_id' => Party::factory()->create()->id], [
        ['description' => 'Fahrzeug', 'unit_price_rp' => $grossRp, 'vat_code_id' => VatCode::byKey($codeKey)->id],
    ]);

    return app(IssueInvoice::class)($draft);
}

function soldBmw(array $terms = []): Sale
{
    $sale = reservedSale($terms);

    return app(ContractSale::class)($sale->stockCycle, ['deposit_rp' => $terms['deposit_rp'] ?? 0]);
}

/** @return array<string, mixed> */
function figuresOf(VatPeriod $period): array
{
    return app(PeriodCalculator::class)($period->refresh());
}

function onlyPeriod(): VatPeriod
{
    return VatPeriod::query()->whereNull('corrects_period_id')->sole();
}

it('books a domestic sale incl. 8.1 % with 0.6 % net tax (acceptance VAT 6)', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile();
        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)(soldBmw(), InvoiceType::Final));

        $events = TaxEvent::query()->where('invoice_id', $invoice->id)->get();
        $period = onlyPeriod();
        $figures = figuresOf($period);

        expect($events)->toHaveCount(2) // the car and the winter wheels
            ->and($events->every(fn (TaxEvent $e): bool => $e->state === TaxEventState::Auto && $e->field === '200' && (float) $e->legal_rate === 8.1))->toBeTrue()
            ->and($events->sum('base_rp'))->toBe(2_770_000)
            ->and($events->first()->rule_key.' '.$events->first()->rule_version)->toBe('invoice_line 2026.1')
            ->and($events->first()->explanationText())->toContain('8.1 % VAT included')->toContain('0.6 %')
            ->and($period->starts_on->toDateString())->toBe('2026-01-01')
            ->and($period->ends_on->toDateString())->toBe('2026-06-30')
            ->and($figures['fields'][200])->toBe(2_770_000)
            ->and($figures['fields'][299])->toBe(2_770_000)
            ->and($figures['payable_rp'])->toBe(16_620) // 27'700 × 0.6 %
            ->and($figures['legal_vat_rp'])->toBe(207_558); // shown to the customer, not owed
    });
});

it('owes CHF 3’000 net tax on CHF 500’000 taxable turnover and closes the half-year (acceptance VAT 7)', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile();

        foreach ([2_000_000, 12_000_000, 15_500_000, 20_500_000] as $gross) {
            issuedInvoice($gross);
        }

        $period = onlyPeriod();
        $preview = figuresOf($period);

        expect($preview['fields'][299])->toBe(50_000_000)
            ->and($preview['payable_rp'])->toBe(300_000)
            ->and($preview['complete'])->toBeFalse() // the half-year is not over yet
            ->and(fn () => app(CloseVatPeriod::class)($period))->toThrow(BusinessRuleException::class, 'runs until 30.06.2026');

        Carbon::setTestNow('2026-07-10 09:00');
        $closed = app(CloseVatPeriod::class)($period->refresh());

        expect($closed->status)->toBe(VatPeriodStatus::Closed)
            ->and($closed->figures['payable_rp'])->toBe(300_000)
            ->and($closed->closed_by)->toBe($this->user->id)
            ->and($closed->reportDocument->category->key)->toBe('vat_report')
            ->and($closed->reportDocument->currentVersion->locked_at)->not->toBeNull()
            ->and($closed->detailDocument->currentVersion->contents())->toContain('invoice_line 2026.1')->toContain(';205000.00;');
    });
});

it('shows "Preview, not complete" until open checks are confirmed (acceptance VAT 3: invoice without VAT shown)', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile();
        issuedInvoice(1_000_000);
        issuedInvoice(500_000, InstallDefaultVatCodes::NO_TAX_SHOWN); // company invoice without VAT shown
        Carbon::setTestNow('2026-07-10');
        $period = onlyPeriod();

        $open = TaxEvent::query()->where('state', TaxEventState::Confirm->value)->sole();
        $figures = figuresOf($period);

        expect($open->explanationText())->toContain('still owes tax')
            ->and($figures['complete'])->toBeFalse()
            ->and($figures['checks'][0]['text'])->toBe(':count entries must be confirmed or resolved.')
            ->and($figures['payable_rp'])->toBe(6_000) // only the confirmed CHF 10'000 so far
            ->and(fn () => app(CloseVatPeriod::class)($period))->toThrow(BusinessRuleException::class, '1 entries must be confirmed');

        app(ConfirmTaxEvent::class)($open);

        expect(figuresOf($period)['payable_rp'])->toBe(9_000)
            ->and(app(CloseVatPeriod::class)($period->refresh())->status)->toBe(VatPeriodStatus::Closed)
            ->and($open->refresh()->explanationText())->toContain('Confirmed by');
    });
});

it('never books input tax for purchases and costs under the net tax rate method (acceptance VAT 1, 3, 4)', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile();
        $cycle = StockCycle::factory()->create();
        $category = CostCategory::query()->where('counts_toward_margin', true)->firstOrFail();

        // 1: private purchase, no VAT; 3: company invoice with VAT shown; 4: workshop invoice on the vehicle
        app(RecordPurchase::class)($cycle, ['seller_party_id' => Party::factory()->create()->id, 'seller_kind' => 'private', 'contract_on' => '2026-02-02', 'price_rp' => 1_500_000]);
        app(ConfirmCost::class)(app(RecordCost::class)(['stock_cycle_id' => $cycle->id, 'category_id' => $category->id, 'incurred_on' => '2026-02-10', 'gross_rp' => 108_100, 'vat_rp' => 8_100, 'description' => 'Garage Muster AG, Service']));
        app(ConfirmCost::class)(app(RecordCost::class)(['stock_cycle_id' => $cycle->id, 'category_id' => $category->id, 'incurred_on' => '2026-02-11', 'gross_rp' => 40_000, 'description' => 'Reifen Huber, ohne MWST-Ausweis']));

        expect(app(CollectTaxEvents::class)())->toBe(0)
            ->and(TaxEvent::query()->count())->toBe(0);

        // The gross costs count in full for the margin; no input tax is deducted.
        $cycle->forceFill(['list_price_rp' => 2_000_000])->save();
        $margin = app(CalculateMargin::class)($cycle->refresh());

        expect($margin->costsRp())->toBe(148_100)
            ->and($margin->marginRp())->toBe(2_000_000 - 1_500_000 - 148_100)
            ->and($margin->netTaxRp)->toBe(12_000) // 0.6 % of CHF 20'000
            ->and($margin->marginAfterVatRp())->toBe(2_000_000 - 1_500_000 - 148_100 - 12_000);
    });
});

it('taxes the full price of a sale with trade-in (acceptance VAT 8)', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile();
        $sale = soldBmw(['trade_in' => ['vehicle' => ['make' => 'VW', 'model' => 'Golf'], 'value_rp' => 800_000, 'payoff_rp' => 0]]);
        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale, InvoiceType::Final));
        $figures = figuresOf(onlyPeriod());

        // The trade-in pays part of the invoice; it does not reduce the consideration.
        expect($invoice->paid_rp)->toBe(800_000)
            ->and($figures['fields'][200])->toBe(2_770_000)
            ->and($figures['fields'][289])->toBe(0)
            ->and($figures['payable_rp'])->toBe(16_620)
            ->and(TaxEvent::query()->where('invoice_id', '!=', $invoice->id)->count())->toBe(0); // the trade-in purchase books nothing
    });
});

it('puts a deposit in half-year 1 and the final invoice in half-year 2 (acceptance VAT 9, agreed basis)', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile();
        $sale = soldBmw(['deposit_rp' => 500_000]);
        app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale, InvoiceType::Deposit));

        Carbon::setTestNow('2026-08-03');
        app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale, InvoiceType::Final));

        $h1 = VatPeriod::query()->whereDate('starts_on', '2026-01-01')->sole();
        $h2 = VatPeriod::query()->whereDate('starts_on', '2026-07-01')->sole();

        expect(figuresOf($h1)['fields'][299])->toBe(500_000)
            ->and(figuresOf($h2)['fields'][299])->toBe(2_270_000) // final invoice less the deposit invoice
            ->and(figuresOf($h1)['payable_rp'] + figuresOf($h2)['payable_rp'])->toBe(16_620);
    });
});

it('puts each payment in the period it was received (acceptance VAT 9, received basis)', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile(['basis' => 'received']);
        $sale = soldBmw(['deposit_rp' => 500_000]);
        $deposit = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale, InvoiceType::Deposit));

        expect(TaxEvent::query()->count())->toBe(0); // issued, not yet paid

        app(RecordPayment::class)(['direction' => 'in', 'paid_on' => '2026-03-20', 'amount_rp' => 500_000, 'method' => 'bank'], [[$deposit, 500_000]]);
        Carbon::setTestNow('2026-08-03');
        $final = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale, InvoiceType::Final));
        $payment = app(RecordPayment::class)(['direction' => 'in', 'paid_on' => '2026-08-20', 'amount_rp' => 1_000_000, 'method' => 'bank'], [[$final, 1_000_000]]);

        $h1 = VatPeriod::query()->whereDate('starts_on', '2026-01-01')->sole();
        $h2 = VatPeriod::query()->whereDate('starts_on', '2026-07-01')->sole();

        expect(figuresOf($h1)['fields'][299])->toBe(500_000)
            ->and(figuresOf($h2)['fields'][299])->toBe(1_000_000)
            ->and(TaxEvent::query()->where('source_id', $payment->allocations->first()->id)->sum('base_rp'))->toEqual(1_000_000);

        // A payment booked by mistake disappears with its entries while the period is open.
        app(RecordPayment::class)->delete($payment);

        expect(figuresOf($h2)['fields'][299])->toBe(0);
    });
});

it('reports a credit note after a closed period in the new period and never changes the closed one (acceptance VAT 11)', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile();
        $invoice = issuedInvoice(2_770_000);
        Carbon::setTestNow('2026-07-10');
        $h1 = app(CloseVatPeriod::class)(onlyPeriod());

        $credit = app(IssueCreditNote::class)($invoice, 'Kauf rückgängig gemacht');
        $h2 = VatPeriod::query()->whereDate('starts_on', '2026-07-01')->sole();
        $figures = figuresOf($h2);

        expect(TaxEvent::query()->where('invoice_id', $credit->id)->sole()->field)->toBe('235')
            ->and($figures['fields'][200])->toBe(0)
            ->and($figures['fields'][235])->toBe(2_770_000)
            ->and($figures['fields'][299])->toBe(-2_770_000)
            ->and($figures['payable_rp'])->toBe(-16_620)
            ->and($h1->refresh()->figures['payable_rp'])->toBe(16_620)
            ->and(fn () => TaxEvent::query()->where('period_id', $h1->id)->first()->forceFill(['base_rp' => 1])->save())
            ->toThrow(QueryException::class, 'closed VAT period');
    });
});

it('books a late payment for a closed period into a correction with full amounts (received basis)', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile(['basis' => 'received']);
        $a = issuedInvoice(1_000_000);
        $b = issuedInvoice(2_000_000);
        app(RecordPayment::class)(['direction' => 'in', 'paid_on' => '2026-03-20', 'amount_rp' => 1_000_000, 'method' => 'bank'], [[$a, 1_000_000]]);
        Carbon::setTestNow('2026-07-10');
        $h1 = app(CloseVatPeriod::class)(onlyPeriod());

        // Found later: CHF 20'000 received in cash on 28 June.
        app(RecordPayment::class)(['direction' => 'in', 'paid_on' => '2026-06-28', 'amount_rp' => 2_000_000, 'method' => 'cash'], [[$b, 2_000_000]]);

        $correction = VatPeriod::query()->where('corrects_period_id', $h1->id)->sole();
        $event = TaxEvent::query()->where('period_id', $correction->id)->sole();

        expect($event->late)->toBeTrue()
            ->and(figuresOf($correction)['fields'][299])->toBe(3_000_000) // replaces the return: full amounts
            ->and($h1->refresh()->figures['fields'][299])->toBe(1_000_000)
            ->and($correction->label())->toContain('correction');
    });
});

it('creates one set of entries per deal, never twice (acceptance VAT 12)', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile();
        $sale = soldBmw();
        app(GenerateContract::class)($sale, 'de', null); // the contract books nothing, the invoice leads

        expect(TaxEvent::query()->count())->toBe(0);

        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale, InvoiceType::Final));
        app(RecordTaxEvents::class)->invoiceIssued($invoice);
        app(CollectTaxEvents::class)();

        expect(TaxEvent::query()->count())->toBe(2)
            ->and(figuresOf(onlyPeriod())['fields'][200])->toBe(2_770_000);
    });
});

it('applies a new net tax rate from its date without changing closed returns (acceptance VAT 13)', function () {
    asTenant($this->tenant, function () {
        $old = aziriVatProfile();
        issuedInvoice(1_000_000);
        Carbon::setTestNow('2026-07-10');
        $h1 = app(CloseVatPeriod::class)(onlyPeriod());

        expect(fn () => app(SaveVatProfile::class)($old->refresh(), ['valid_from' => '2026-01-01', 'method' => 'net_tax_rate', 'basis' => 'agreed', 'period' => 'half_year'], [['id' => $old->netTaxRates->first()->id, 'activity' => 'Autohandel', 'rate' => '0.5']]))
            ->toThrow(BusinessRuleException::class, 'used by a closed VAT period')
            ->and(fn () => aziriVatProfile(['valid_from' => '2026-06-01'], [['activity' => 'Autohandel', 'rate' => '0.5']]))
            ->toThrow(BusinessRuleException::class, 'must start after 30.06.2026');

        aziriVatProfile(['valid_from' => '2026-07-01'], [['activity' => 'Autohandel', 'activity_code' => '11111', 'rate' => '0.5']]);
        issuedInvoice(1_000_000);

        $h2 = VatPeriod::query()->whereDate('starts_on', '2026-07-01')->sole();

        expect($old->refresh()->valid_to->toDateString())->toBe('2026-06-30')
            ->and(figuresOf($h2)['payable_rp'])->toBe(5_000)
            ->and($h1->refresh()->figures['payable_rp'])->toBe(6_000)
            ->and(fn () => app(CloseVatPeriod::class)($h1))->toThrow(BusinessRuleException::class, 'already closed');
    });
});

it('exports a complete eCH-0217 XML, then records submission and payment (acceptance VAT 14)', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile();
        issuedInvoice(50_000_000);
        issuedInvoice(1_000_000, InstallDefaultVatCodes::EXCLUDED);
        $credit = issuedInvoice(2_000_000);
        app(IssueCreditNote::class)($credit, 'Rabatt', 500_000);
        Carbon::setTestNow('2026-07-10 08:30:00');
        $period = app(CloseVatPeriod::class)(onlyPeriod());

        $result = app(ExportVatPeriod::class)($period);
        $xml = $period->refresh()->xmlDocument->currentVersion->contents();
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $x = new DOMXPath($doc);
        $x->registerNamespace('v', 'http://www.ech.ch/xmlns/eCH-0217/2');
        $value = fn (string $path): string => (string) $x->evaluate("string({$path})");

        expect($result['validated'])->toBeFalse() // no XSD configured in tests
            ->and($period->status)->toBe(VatPeriodStatus::Exported)
            ->and($doc->documentElement->localName)->toBe('VATDeclaration')
            ->and($value('/v:VATDeclaration/v:generalInformation/v:uid'))->toBe('CHE404944758')
            ->and($value('/v:VATDeclaration/v:generalInformation/v:organisationName'))->toBe('Aziri Automobile GmbH')
            ->and($value('/v:VATDeclaration/v:generalInformation/v:reportingPeriodFrom'))->toBe('2026-01-01')
            ->and($value('/v:VATDeclaration/v:generalInformation/v:reportingPeriodTill'))->toBe('2026-06-30')
            ->and($value('/v:VATDeclaration/v:generalInformation/v:typeOfSubmission'))->toBe('1')
            ->and($value('/v:VATDeclaration/v:generalInformation/v:formOfReporting'))->toBe('1')
            ->and($value('/v:VATDeclaration/v:turnoverComputation/v:totalConsideration'))->toBe('530000.00')
            ->and($value('/v:VATDeclaration/v:turnoverComputation/v:suppliesExemptFromTax'))->toBe('10000.00')
            ->and($value('/v:VATDeclaration/v:turnoverComputation/v:reductionOfConsideration'))->toBe('5000.00')
            ->and($value('/v:VATDeclaration/v:simpleTaxRateMethod/v:suppliesPerTaxRate/v:activityID'))->toBe('11111')
            ->and($value('/v:VATDeclaration/v:simpleTaxRateMethod/v:suppliesPerTaxRate/v:taxRate'))->toBe('0.60')
            ->and($value('/v:VATDeclaration/v:simpleTaxRateMethod/v:suppliesPerTaxRate/v:turnover'))->toBe('515000.00')
            ->and($value('/v:VATDeclaration/v:payableTax'))->toBe('3090.00')
            ->and($x->evaluate('count(/v:VATDeclaration/*)'))->toEqual(4.0);

        expect(fn () => app(RecordVatSubmission::class)->paid($period, '2026-07-20'))->toThrow(BusinessRuleException::class, 'submitted first');

        [$receipt, $name] = explode('|', fakeFile('%PDF-1.4 Quittung ESTV', 'quittung.pdf'));
        $submitted = app(RecordVatSubmission::class)->submitted($period, '2026-07-10', 'ESTV-4711', $receipt, $name);

        expect($submitted->status)->toBe(VatPeriodStatus::Submitted)
            ->and($submitted->submissionDocument->category->key)->toBe('vat_confirmation')
            ->and(app(RecordVatSubmission::class)->paid($submitted, '2026-07-20')->status)->toBe(VatPeriodStatus::Paid);
    });
});

it('refuses the export without activity code or when the schema rejects the file', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile([], [['activity' => 'Autohandel', 'rate' => '0.6']]);
        issuedInvoice(1_000_000);
        Carbon::setTestNow('2026-07-10');
        $period = app(CloseVatPeriod::class)(onlyPeriod());

        expect(fn () => app(ExportVatPeriod::class)($period))->toThrow(BusinessRuleException::class, 'five-digit ESTV activity code');

        $period->forceFill(['figures' => [...$period->figures, 'rates' => [[...$period->figures['rates'][0], 'activity_code' => '11111']]]])->save();
        [$xsd] = explode('|', fakeFile('<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:other"><xs:element name="Other"/></xs:schema>', 'other.xsd'));
        config(['dealer.vat.ech0217_xsd' => $xsd]);

        expect(fn () => app(ExportVatPeriod::class)($period->refresh()))->toThrow(BusinessRuleException::class, 'does not match the eCH-0217 schema');
    });
});

it('blocks entries until the VAT settings exist, and picks them up afterwards', function () {
    asTenant($this->tenant, function () {
        issuedInvoice(1_000_000);
        $blocked = TaxEvent::query()->sole();

        expect($blocked->state)->toBe(TaxEventState::Blocked)
            ->and($blocked->period_id)->toBeNull()
            ->and($blocked->missingText())->toContain('Set them up');

        aziriVatProfile();

        expect(app(CollectTaxEvents::class)())->toBe(1)
            ->and(TaxEvent::query()->sole()->state)->toBe(TaxEventState::Auto)
            ->and(figuresOf(onlyPeriod())['payable_rp'])->toBe(6_000);
    });
});

it('books nothing for a dealer that is not VAT-registered', function () {
    $dealer = makeDealer(['slug' => 'klein', 'zip' => '3000', 'city' => 'Bern']);

    asTenant($dealer, function () {
        BankAccount::factory()->create(['is_default' => true]);
        issuedInvoice(1_000_000, InstallDefaultVatCodes::NO_TAX_SHOWN);

        expect(TaxEvent::query()->count())->toBe(0);

        app(SaveVatProfile::class)(null, ['valid_from' => '2026-01-01', 'liable' => false, 'method' => 'net_tax_rate', 'basis' => 'agreed', 'period' => 'year'], []);
        app(CollectTaxEvents::class)();

        expect(TaxEvent::query()->count())->toBe(0)
            ->and(VatCode::defaultForSales()->key)->toBe(InstallDefaultVatCodes::NO_TAX_SHOWN);
    });
});

it('asks for confirmation of exports and blocks unsupported cases', function () {
    asTenant($this->tenant, function () {
        aziriVatProfile();
        issuedInvoice(3_000_000, InstallDefaultVatCodes::EXPORT_EXEMPT);
        $export = TaxEvent::query()->sole();

        expect($export->state)->toBe(TaxEventState::Confirm)
            ->and($export->field)->toBe('220')
            ->and($export->tax_rp)->toBe(0)
            ->and($export->explanationText())->toContain('proof of export');

        app(ConfirmTaxEvent::class)($export);
        $figures = figuresOf(onlyPeriod());

        expect($figures['fields'][200])->toBe(3_000_000)
            ->and($figures['fields'][220])->toBe(3_000_000)
            ->and($figures['fields'][299])->toBe(0)
            ->and($figures['payable_rp'])->toBe(0);

        // The effective method is prepared, not released: its entries are blocked.
        aziriVatProfile(['valid_from' => '2026-03-01', 'method' => 'effective'], []);
        issuedInvoice(1_000_000);

        expect(TaxEvent::query()->where('state', TaxEventState::Blocked->value)->sole()->missingText())->toContain('effective method');
    });
});

it('assigns periods by the half-year and creates them on demand', function () {
    asTenant($this->tenant, function () {
        $profile = aziriVatProfile(['period' => 'quarter']);
        $periods = app(VatPeriods::class);

        $q3 = $periods->for(Carbon::parse('2026-08-15'), $profile);

        expect($q3->starts_on->toDateString())->toBe('2026-07-01')
            ->and($q3->ends_on->toDateString())->toBe('2026-09-30')
            ->and($periods->for(Carbon::parse('2026-09-30'), $profile)->id)->toBe($q3->id);
    });
});
