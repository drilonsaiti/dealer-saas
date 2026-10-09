<?php

use App\Domain\Checklists\Actions\SyncChecklist;
use App\Domain\Checklists\Actions\TickChecklistItem;
use App\Domain\Checklists\Models\ChecklistItem;
use App\Domain\Financing\Actions\ChangeFinancingStatus;
use App\Domain\Financing\Actions\ExerciseBuyback;
use App\Domain\Financing\Actions\SaveFinancing;
use App\Domain\Financing\Enums\BuybackStatus;
use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Financing\Models\Financing;
use App\Domain\Invoicing\Actions\CreateInvoiceFromSale;
use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Enums\InvoiceLineKind;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Models\Party;
use App\Domain\Payments\Actions\ImportBankStatement;
use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Purchasing\Enums\PurchaseType;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Sales\Actions\HandOverVehicle;
use App\Domain\Sales\Actions\ReserveVehicle;
use App\Domain\Sales\Enums\PaymentType;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vat\Actions\SaveVatProfile;
use App\Domain\Vat\Models\TaxEvent;
use App\Domain\Vat\Support\VatMath;
use App\Domain\Vehicles\Actions\SetCode178;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\Code178Status;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;

/*
 * Acceptance test 7: leasing sale with payout and code 178, worked from the Cembra contract
 * 4032480511 (BMW X3, cash price CHF 26'900, first instalment CHF 4'500 collected by the
 * dealer, 49 months, residual CHF 12'283 with buy-back, CHF 300 a month).
 */

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();
    Carbon::setTestNow('2026-10-09 10:00');
    $this->tenant = makeDealer(['slug' => 'aziri', 'street' => 'Industriestrasse 5', 'zip' => '3052', 'city' => 'Zollikofen', 'vat_number' => 'CHE-404.944.758 MWST']);
    $this->actingAs(makeMember($this->tenant, Role::Administrator));
    $this->account = asTenant($this->tenant, fn () => BankAccount::factory()->create(['iban' => 'CH2106300505282032675', 'qr_iban' => null, 'is_default' => true]));
});

afterEach(fn () => Carbon::setTestNow());

/** The BMW X3 for CHF 26'900 (no extras), leased through Cembra. */
function cembraLeasing(): Financing
{
    $sale = reservedSale(['items' => []]);
    $cembra = Party::factory()->create(['kind' => 'company', 'company_name' => 'Cembra Money Bank AG', 'first_name' => null, 'last_name' => null, 'zip' => '8048', 'city' => 'Zürich']);

    return app(SaveFinancing::class)($sale, [
        'partner_party_id' => $cembra->id,
        'kind' => 'leasing',
        'collection_rp' => 450_000,
        'term_months' => 49,
        'km_per_year' => 15_000,
        'residual_rp' => 1_228_300,
        'monthly_rate_rp' => 30_000,
        'has_buyback' => true,
    ]);
}

/**
 * @param  list<array{amount: string, ref: string, id: string}>  $entries
 */
function leasingCamt(array $entries): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.08"><BkToCstmrDbtCdtNtfctn><GrpHdr><MsgId>L1</MsgId></GrpHdr><Ntfctn><Id>N1</Id><Acct><Id><IBAN>CH2106300505282032675</IBAN></Id></Acct>';

    foreach ($entries as $e) {
        $xml .= '<Ntry><Amt Ccy="CHF">'.$e['amount'].'</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts><BookgDt><Dt>2026-10-20</Dt></BookgDt><AcctSvcrRef>'.$e['id'].'</AcctSvcrRef>'
            .'<NtryDtls><TxDtls><Amt Ccy="CHF">'.$e['amount'].'</Amt><CdtDbtInd>CRDT</CdtDbtInd><RltdPties><Dbtr><Pty><Nm>Cembra Money Bank AG</Nm></Pty></Dbtr></RltdPties>'
            .'<RmtInf><Strd><CdtrRefInf><Ref>'.$e['ref'].'</Ref></CdtrRefInf></Strd></RmtInf></TxDtls></NtryDtls></Ntry>';
    }

    $path = tempnam(sys_get_temp_dir(), 'camt');
    file_put_contents($path, $xml.'</Ntfctn></BkToCstmrDbtCdtNtfctn></Document>');

    return $path;
}

it('runs a leasing sale from application to payout, handover and code 178 (acceptance test 7)', function () {
    asTenant($this->tenant, function () {
        app(SaveVatProfile::class)(null, ['valid_from' => '2026-01-01', 'method' => 'net_tax_rate', 'basis' => 'agreed', 'period' => 'half_year'], [['activity' => 'Autohandel', 'rate' => '0.6']]);
        $financing = cembraLeasing();
        $sale = $financing->sale->refresh();

        // 1. Application: the bank is invoiced, the customer stays buyer and holder.
        expect($financing->status)->toBe(FinancingStatus::Applied)
            ->and($financing->cash_price_rp)->toBe(2_690_000)
            ->and($financing->payout_expected_rp)->toBe(2_240_000) // 26'900 − 4'500
            ->and($sale->payment_type)->toBe(PaymentType::Leasing)
            ->and($sale->invoice_recipient_party_id)->toBe($financing->partner_party_id)
            ->and($sale->holder_party_id)->toBe($sale->buyer_party_id)
            ->and($financing->partner->hasRole(PartyRole::FinancingPartner))->toBeTrue();

        app(ContractSale::class)($sale->stockCycle, []);
        app(ChangeFinancingStatus::class)($financing, FinancingStatus::Approved);

        // 2./3. Contract received: revocation period, partner checklist, buy-back obligation.
        app(ChangeFinancingStatus::class)($financing->refresh(), FinancingStatus::ContractReceived, ['received_on' => '2026-10-09', 'contract_number' => '4032480511']);
        $financing->refresh();
        $checklist = app(SyncChecklist::class)->partner($financing);

        expect($financing->revocation_until->toDateString())->toBe('2026-10-23')
            ->and($checklist->items)->toHaveCount(8)
            ->and($financing->buyback->amount_rp)->toBe(1_228_300)
            ->and($financing->buyback->due_on->toDateString())->toBe('2030-11-09') // + 49 months
            ->and($financing->buyback->remind_on->toDateString())->toBe('2030-08-09')
            ->and(fn () => app(ChangeFinancingStatus::class)($financing, FinancingStatus::Signed))->not->toThrow(Exception::class)
            ->and(fn () => app(ChangeFinancingStatus::class)($financing->refresh(), FinancingStatus::DocumentsSent))->toThrow(BusinessRuleException::class, 'The bank still needs');

        // 4. Invoice to the bank: full price incl. VAT, the collected instalment credited.
        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale->refresh(), InvoiceType::Final));
        $credit = $invoice->lines->firstWhere('kind', InvoiceLineKind::CollectionCredit);

        expect($invoice->recipient_party_id)->toBe($financing->partner_party_id)
            ->and($invoice->total_rp)->toBe(2_240_000)
            ->and($invoice->vat_rp)->toBe(VatMath::includedVat(2_690_000, 8.1))
            ->and($credit->total_rp)->toBe(-450_000)
            ->and($credit->vat_code_id)->toBeNull()
            ->and(TaxEvent::query()->sum('base_rp'))->toEqual(2_690_000); // VAT on the full price

        // The remaining items are ticked as the papers come back.
        app(SyncChecklist::class)->partner($financing)->items
            ->reject(fn (ChecklistItem $item): bool => $item->isDone())
            ->each(fn (ChecklistItem $item) => app(TickChecklistItem::class)($item));
        app(ChangeFinancingStatus::class)($financing->refresh(), FinancingStatus::DocumentsSent);

        expect($financing->refresh()->status)->toBe(FinancingStatus::DocumentsSent)
            ->and($financing->payout_due_on->toDateString())->toBe('2026-10-19');

        // 5. No handover before the payout; the revocation period is a warning.
        $keys = app(SyncChecklist::class)->handover($sale)->items->firstWhere('key', 'keys');

        expect(app(HandOverVehicle::class)->warnings($sale))->toHaveCount(1)
            ->and(fn () => app(HandOverVehicle::class)($sale, 80_000, null, [$keys->id]))->toThrow(BusinessRuleException::class, 'Paid or leasing paid out');

        // 6. Payout from the bank import: the financing is "paid out", the handover item ticks itself.
        Carbon::setTestNow('2026-10-24 09:00');
        app(ImportBankStatement::class)($this->account, leasingCamt([['amount' => '22400.00', 'ref' => $invoice->qr_reference, 'id' => 'P1']]), 'camt054.xml');

        expect($financing->refresh()->status)->toBe(FinancingStatus::PaidOut)
            ->and($financing->payout_received_on->toDateString())->toBe('2026-10-20')
            ->and(app(HandOverVehicle::class)->warnings($sale))->toBe([]);

        app(HandOverVehicle::class)($sale->refresh(), 80_000, null, [$keys->id]);

        expect($sale->refresh()->status)->toBe(SaleStatus::Delivered);

        // 7. Code 178: entered by the bank; the car cannot be resold until it is cleared.
        app(SetCode178::class)($sale->stockCycle->vehicle, Code178Status::Entered, '2026-10-28');
        $buyback = app(ExerciseBuyback::class)($financing->buyback->refresh(), '2030-11-09', 98_000);

        expect($buyback->purchase->purchase_type)->toBe(PurchaseType::Buyback)
            ->and($buyback->purchase->price_rp)->toBe(1_228_300)
            ->and($buyback->vehicle_id)->toBe($sale->stockCycle->vehicle_id)
            ->and($financing->buyback->refresh()->status)->toBe(BuybackStatus::Exercised);

        foreach ([StockCycleStatus::Arrived, StockCycleStatus::InPreparation, StockCycleStatus::ReadyForSale] as $status) {
            app(TransitionStockCycle::class)($buyback->refresh(), $status);
        }

        $customer = Party::factory()->create();

        expect(fn () => app(ReserveVehicle::class)($buyback->refresh(), ['buyer_party_id' => $customer->id, 'price_rp' => 1_800_000]))
            ->toThrow(BusinessRuleException::class, 'Code 178 is still entered');

        app(SetCode178::class)($buyback->vehicle, Code178Status::Cleared);

        expect(app(ReserveVehicle::class)($buyback->refresh(), ['buyer_party_id' => $customer->id, 'price_rp' => 1_800_000])->status)->toBe(SaleStatus::Reserved);
    });
});

it('goes back to "documents sent" when the payout is removed, and the bank cannot be dropped once invoiced', function () {
    asTenant($this->tenant, function () {
        $financing = cembraLeasing();
        $sale = $financing->sale;
        app(ContractSale::class)($sale->stockCycle, []);
        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale->refresh(), InvoiceType::Final));
        $payment = app(RecordPayment::class)(['direction' => 'in', 'paid_on' => '2026-10-20', 'amount_rp' => 2_240_000, 'method' => 'financing_payout'], [[$invoice, 2_240_000]]);

        expect($financing->refresh()->status)->toBe(FinancingStatus::PaidOut);

        app(RecordPayment::class)->delete($payment);

        expect($financing->refresh()->status)->toBe(FinancingStatus::DocumentsSent)
            ->and($financing->payout_received_on)->toBeNull();

        $financing->forceFill(['status' => FinancingStatus::Approved])->save();

        expect(fn () => app(ChangeFinancingStatus::class)($financing, FinancingStatus::Cancelled))->toThrow(BusinessRuleException::class, 'Credit it first');
    });
});

it('refuses a collected instalment above the cash price and a financing on a delivered sale', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale(['items' => []]);
        $bank = Party::factory()->create();

        expect(fn () => app(SaveFinancing::class)($sale, ['partner_party_id' => $bank->id, 'collection_rp' => 2_690_000]))
            ->toThrow(BusinessRuleException::class, 'less than the cash price');

        $sale->forceFill(['status' => SaleStatus::Delivered])->save();

        expect(fn () => app(SaveFinancing::class)($sale, ['partner_party_id' => $bank->id]))->toThrow(BusinessRuleException::class, 'already delivered');
    });
});

it('releases the buy-back obligation when the financing is rejected', function () {
    asTenant($this->tenant, function () {
        $financing = cembraLeasing();
        app(ChangeFinancingStatus::class)($financing, FinancingStatus::Rejected);

        expect($financing->refresh()->status)->toBe(FinancingStatus::Rejected)
            ->and(Sale::query()->find($financing->sale_id)->invoice_recipient_party_id)->toBeNull()
            ->and(Sale::query()->find($financing->sale_id)->financing)->toBeNull();
    });
});
