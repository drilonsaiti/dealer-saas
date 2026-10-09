<?php

use App\Domain\Documents\Enums\OcrStatus;
use App\Domain\Invoicing\Actions\CreateInvoiceFromSale;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Actions\SaveInvoiceDraft;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\QrBill\QrBill;
use App\Domain\Invoicing\QrBill\QrReference;
use App\Domain\Invoicing\Support\InvoiceRenderer;
use App\Domain\Parties\Models\Party;
use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Purchasing\Enums\PaymentStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Actions\CancelSale;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vat\Actions\InstallDefaultVatCodes;
use App\Domain\Vat\Models\VatCode;
use App\Domain\Vat\Models\VatRate;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;

/*
 * Acceptance test 10 (Swiss QR invoice) and the invoice rules: numbers only on issue and
 * gap-free, issued invoices never change, corrections by credit note, payments always derived.
 */

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();

    $this->tenant = makeDealer(['name' => 'Aziri Automobile GmbH', 'legal_name' => 'Aziri Automobile GmbH', 'slug' => 'aziri', 'street' => 'Industriestrasse 5', 'zip' => '3052', 'city' => 'Zollikofen', 'vat_number' => 'CHE-404.944.758 MWST']);
    $this->user = makeMember($this->tenant, Role::Sales);
    $this->actingAs($this->user);
    asTenant($this->tenant, fn () => BankAccount::factory()->create(['label' => 'PostFinance', 'iban' => 'CH5604835012345678009', 'qr_iban' => null, 'is_default' => true]));
});

/** The reserved BMW sale (CHF 26'900 + 800 winter wheels), contracted with a deposit. */
function contractedSale(int $depositRp = 0): Sale
{
    $sale = reservedSale(['deposit_rp' => $depositRp]);

    return app(ContractSale::class)($sale->stockCycle, ['deposit_rp' => $depositRp]);
}

it('issues a final invoice with VAT and a valid Swiss QR bill (acceptance test 10)', function () {
    asTenant($this->tenant, function () {
        $sale = contractedSale();
        $draft = app(CreateInvoiceFromSale::class)($sale, InvoiceType::Final);

        expect($draft->status)->toBe(InvoiceStatus::Draft)
            ->and($draft->number)->toBeNull()
            ->and($draft->total_rp)->toBe(2_770_000);

        $invoice = app(IssueInvoice::class)($draft);
        $snapshot = $invoice->document->currentVersion->data_snapshot;
        $bill = InvoiceRenderer::qrBill($snapshot);
        $payload = explode("\n", $bill->payload());

        expect($invoice->number)->toBe('RE-00001')
            ->and($invoice->status)->toBe(InvoiceStatus::Issued)
            ->and($invoice->issued_on->toDateString())->toBe(today()->toDateString())
            ->and($invoice->vat_rp)->toBe(207_558) // 27'700 × 8.1 / 108.1
            ->and($invoice->net_rp)->toBe(2_770_000 - 207_558)
            ->and($invoice->reference_type)->toBe('SCOR')
            ->and(QrReference::isValidScor($invoice->qr_reference))->toBeTrue()
            ->and($sale->refresh()->status)->toBe(SaleStatus::Invoiced)
            ->and($invoice->document->category->key)->toBe('invoice')
            ->and($invoice->document->currentVersion->locked_at)->not->toBeNull()
            ->and($invoice->document->currentVersion->ocr_status)->toBe(OcrStatus::NotNeeded)
            ->and($payload)->toHaveCount(31)
            ->and($payload[3])->toBe('CH5604835012345678009')
            ->and($payload[5])->toBe('Aziri Automobile GmbH')
            ->and($payload[18])->toBe('27700.00')
            ->and($payload[21])->toBe('Anna Muster')
            ->and($payload[27])->toBe('SCOR')
            ->and($payload[30])->toBe('EPD');

        expect(app(InvoiceRenderer::class)->html($snapshot))
            ->toContain('Zahlteil')
            ->toContain('MWST 8.1 % auf CHF 27’700.00');
    });
});

it('uses a QR reference with a QR-IBAN', function () {
    asTenant($this->tenant, function () {
        BankAccount::query()->update(['is_default' => false]);
        BankAccount::factory()->create(['label' => 'Valiant', 'is_default' => true]);

        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)(contractedSale(), InvoiceType::Final));

        expect($invoice->reference_type)->toBe(QrBill::TYPE_QRR)
            ->and($invoice->qr_reference)->toHaveLength(27)
            ->and(QrReference::isValidQrr($invoice->qr_reference))->toBeTrue()
            ->and($invoice->qr_iban)->toBe('CH8130024505282032675');
    });
});

it('deducts the deposit invoice on the final invoice', function () {
    asTenant($this->tenant, function () {
        $sale = contractedSale(500_000);
        $deposit = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale, InvoiceType::Deposit));
        $final = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale, InvoiceType::Final));

        expect($deposit->number)->toBe('RE-00001')
            ->and($deposit->total_rp)->toBe(500_000)
            ->and($final->number)->toBe('RE-00002')
            ->and($final->total_rp)->toBe(2_270_000)
            ->and($final->vat_rp)->toBe(207_558 - 37_465)
            ->and($final->lines->last()->description)->toContain('RE-00001')
            ->and(fn () => app(CreateInvoiceFromSale::class)($sale, InvoiceType::Final))->toThrow(BusinessRuleException::class, 'already has');
    });
});

it('books the trade-in as payment on the final invoice', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale(['trade_in' => ['vehicle' => ['make' => 'VW', 'model' => 'Golf'], 'value_rp' => 800_000, 'payoff_rp' => 200_000]]);
        $sale = app(ContractSale::class)($sale->stockCycle, []);

        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale, InvoiceType::Final));
        $snapshot = $invoice->document->currentVersion->data_snapshot;

        expect($invoice->paid_rp)->toBe(600_000)
            ->and($invoice->status)->toBe(InvoiceStatus::PartiallyPaid)
            ->and($invoice->openRp())->toBe(2_170_000)
            ->and($invoice->allocations()->first()->payment->method)->toBe(PaymentMethod::TradeInOffset)
            ->and($snapshot['qr']['amount_rp'])->toBe(2_170_000);
    });
});

it('assigns numbers only on issue and without gaps', function () {
    asTenant($this->tenant, function () {
        $party = Party::factory()->create();
        $code = VatCode::byKey(InstallDefaultVatCodes::TAXABLE_NORMAL)->id;
        $line = [['description' => 'Service', 'unit_price_rp' => 25_000, 'vat_code_id' => $code]];

        $a = app(SaveInvoiceDraft::class)(null, ['type' => InvoiceType::Standard, 'recipient_party_id' => $party->id], $line);
        $b = app(SaveInvoiceDraft::class)(null, ['type' => InvoiceType::Standard, 'recipient_party_id' => $party->id], $line);
        $a->delete();

        expect(app(IssueInvoice::class)($b)->number)->toBe('RE-00001')
            ->and(fn () => app(SaveInvoiceDraft::class)($b->refresh(), [], $line))->toThrow(BusinessRuleException::class, 'cannot be changed')
            ->and(fn () => app(IssueInvoice::class)($b->refresh()))->toThrow(BusinessRuleException::class, 'already issued');
    });
});

it('corrects an invoice only with a credit note, and then the sale can be cancelled', function () {
    asTenant($this->tenant, function () {
        $sale = contractedSale();
        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale, InvoiceType::Final));

        expect(fn () => app(CancelSale::class)($sale->refresh(), 'Kunde tritt zurück'))->toThrow(BusinessRuleException::class);

        $credit = app(IssueCreditNote::class)($invoice, 'Kunde tritt zurück');

        expect($credit->number)->toBe('GS-00001')
            ->and($credit->type)->toBe(InvoiceType::CreditNote)
            ->and($credit->total_rp)->toBe(-2_770_000)
            ->and($credit->vat_rp)->toBe(-207_558)
            ->and($credit->qr_reference)->toBeNull()
            ->and($credit->document->category->key)->toBe('credit_note')
            ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled)
            ->and($invoice->number)->toBe('RE-00001')
            ->and($sale->refresh()->status)->toBe(SaleStatus::Contracted);

        app(CancelSale::class)($sale, 'Kunde tritt zurück');
        expect($sale->refresh()->status)->toBe(SaleStatus::Cancelled);
    });
});

it('credits part of an invoice', function () {
    asTenant($this->tenant, function () {
        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)(contractedSale(), InvoiceType::Final));
        app(IssueCreditNote::class)($invoice, 'Kulanz Kratzer', 50_000);

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
            ->and($invoice->openRp())->toBe(2_720_000)
            ->and(fn () => app(IssueCreditNote::class)($invoice, 'Zu viel', 3_000_000))->toThrow(BusinessRuleException::class, 'At most');
    });
});

it('derives paid and open amounts from the payments', function () {
    asTenant($this->tenant, function () {
        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)(contractedSale(), InvoiceType::Final));
        $pay = app(RecordPayment::class);

        $pay(['direction' => 'in', 'paid_on' => '2026-10-09', 'amount_rp' => 500_000, 'method' => 'cash'], [[$invoice, 500_000]]);
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::PartiallyPaid)->and($invoice->openRp())->toBe(2_270_000);

        expect(fn () => $pay(['direction' => 'in', 'paid_on' => '2026-10-10', 'amount_rp' => 3_000_000, 'method' => 'bank'], [[$invoice->refresh(), 3_000_000]]))
            ->toThrow(BusinessRuleException::class, 'is still open');

        $payment = $pay(['direction' => 'in', 'paid_on' => '2026-10-10', 'amount_rp' => 2_270_000, 'method' => 'bank'], [[$invoice->refresh(), 2_270_000]]);
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);

        $pay->delete($payment);
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::PartiallyPaid);

        // Outgoing: CHF 22'800 by bank + CHF 5'000 cash for one purchase (BMW 640d).
        $purchase = Purchase::factory()->create(['price_rp' => 2_780_000]);
        $pay(['direction' => 'out', 'paid_on' => '2026-10-01', 'amount_rp' => 2_280_000, 'method' => 'bank'], [[$purchase, 2_280_000]]);
        expect($purchase->refresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid);
        $pay(['direction' => 'out', 'paid_on' => '2026-10-01', 'amount_rp' => 500_000, 'method' => 'cash'], [[$purchase, 500_000]]);
        expect($purchase->refresh()->payment_status)->toBe(PaymentStatus::Paid);
    });
});

it('prints no VAT for a dealer without VAT number', function () {
    asTenant($this->tenant, function () {
        $this->tenant->forceFill(['vat_number' => null])->save();
        tenantContext()->set($this->tenant->refresh());

        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)(contractedSale(), InvoiceType::Final));

        expect($invoice->vat_rp)->toBe(0)
            ->and(app(InvoiceRenderer::class)->html($invoice->document->currentVersion->data_snapshot))->toContain('Nicht MWST-pflichtig.');
    });
});

it('takes the VAT rate valid on the date', function () {
    expect(VatRate::percentFor('normal', Carbon::parse('2023-12-31')))->toBe(7.7)
        ->and(VatRate::percentFor('normal', Carbon::parse('2024-01-01')))->toBe(8.1)
        ->and(VatRate::percentFor('reduced', Carbon::parse('2026-10-09')))->toBe(2.6);
});

it('builds valid QR and creditor references', function () {
    expect(QrReference::isValidQrr('210000000003139471430009017'))->toBeTrue()
        ->and(QrReference::isValidQrr('210000000003139471430009018'))->toBeFalse()
        ->and(QrReference::isValidQrr(QrReference::qrr('271')))->toBeTrue()
        ->and(QrReference::scor('539007547034'))->toBe('RF18539007547034')
        ->and(QrReference::isValidScor('RF18539007547034'))->toBeTrue()
        ->and(QrReference::format('210000000003139471430009017'))->toBe('21 00000 00003 13947 14300 09017');
});
