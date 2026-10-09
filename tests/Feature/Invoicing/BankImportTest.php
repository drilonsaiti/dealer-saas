<?php

use App\Domain\Invoicing\Actions\CreateInvoiceFromSale;
use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Payments\Actions\ImportBankStatement;
use App\Domain\Payments\Actions\MatchBankTransaction;
use App\Domain\Payments\Enums\MatchStatus;
use App\Domain\Payments\Models\BankTransaction;
use App\Domain\Payments\Support\CamtParser;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Role;
use App\Support\BusinessRuleException;

/*
 * Bank import (camt.054 / camt.053): QR references are matched automatically, other credits
 * are proposed or assigned by hand; importing the same file twice changes nothing.
 */

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();
    $this->tenant = makeDealer(['slug' => 'aziri', 'street' => 'Industriestrasse 5', 'zip' => '3052', 'city' => 'Zollikofen', 'vat_number' => 'CHE-404.944.758 MWST']);
    $this->actingAs(makeMember($this->tenant, Role::Accounting));
    $this->account = asTenant($this->tenant, fn () => BankAccount::factory()->create(['label' => 'Valiant', 'is_default' => true]));
});

/**
 * @param  list<array{amount: string, ref?: string|null, name?: string, id: string, debit?: bool}>  $entries
 */
function camt054(string $iban, array $entries): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.08"><BkToCstmrDbtCdtNtfctn><GrpHdr><MsgId>M1</MsgId><CreDtTm>2026-10-09T08:00:00</CreDtTm></GrpHdr><Ntfctn><Id>N1</Id><Acct><Id><IBAN>'.$iban.'</IBAN></Id></Acct>';

    foreach ($entries as $e) {
        $indicator = ($e['debit'] ?? false) ? 'DBIT' : 'CRDT';
        $party = ($e['debit'] ?? false) ? 'Cdtr' : 'Dbtr';
        $xml .= '<Ntry><Amt Ccy="CHF">'.$e['amount'].'</Amt><CdtDbtInd>'.$indicator.'</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts><BookgDt><Dt>2026-10-08</Dt></BookgDt><ValDt><Dt>2026-10-08</Dt></ValDt><AcctSvcrRef>'.$e['id'].'</AcctSvcrRef>'
            .'<NtryDtls><TxDtls><Refs><AcctSvcrRef>'.$e['id'].'-1</AcctSvcrRef></Refs><Amt Ccy="CHF">'.$e['amount'].'</Amt><CdtDbtInd>'.$indicator.'</CdtDbtInd>'
            .'<RltdPties><'.$party.'><Pty><Nm>'.($e['name'] ?? 'Anna Muster').'</Nm></Pty></'.$party.'></RltdPties>'
            .'<RmtInf>'.(isset($e['ref']) ? '<Strd><CdtrRefInf><Tp><CdOrPrtry><Prtry>QRR</Prtry></CdOrPrtry></Tp><Ref>'.$e['ref'].'</Ref></CdtrRefInf></Strd>' : '<Ustrd>Auto BMW</Ustrd>').'</RmtInf>'
            .'</TxDtls></NtryDtls></Ntry>';
    }

    return $xml.'</Ntfctn></BkToCstmrDbtCdtNtfctn></Document>';
}

function statementFile(string $xml): string
{
    $path = tempnam(sys_get_temp_dir(), 'camt');
    file_put_contents($path, $xml);

    return $path;
}

it('books a QR-referenced payment automatically and skips the same file the second time', function () {
    asTenant($this->tenant, function () {
        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)(app(ContractSale::class)(reservedSale()->stockCycle, []), InvoiceType::Final));
        $file = statementFile(camt054('CH2106300505282032675', [
            ['amount' => '27700.00', 'ref' => $invoice->qr_reference, 'id' => 'A1'],
            ['amount' => '12.50', 'id' => 'A2', 'debit' => true, 'name' => 'Valiant Bank'],
        ]));

        $summary = app(ImportBankStatement::class)($this->account, $file, 'camt054.xml');

        expect($summary)->toBe(['new' => 2, 'skipped' => 0, 'matched' => 1, 'proposed' => 0, 'open' => 1])
            ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(BankTransaction::query()->where('match_status', 'matched')->first()->payment->paid_on->toDateString())->toBe('2026-10-08')
            ->and(app(ImportBankStatement::class)($this->account, $file, 'camt054.xml'))->toBe(['new' => 0, 'skipped' => 2, 'matched' => 0, 'proposed' => 0, 'open' => 0]);
    });
});

it('proposes an invoice with the same open amount, and the user confirms, ignores or undoes', function () {
    asTenant($this->tenant, function () {
        $invoice = app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)(app(ContractSale::class)(reservedSale()->stockCycle, []), InvoiceType::Final));
        app(ImportBankStatement::class)($this->account, statementFile(camt054('CH2106300505282032675', [
            ['amount' => '27700.00', 'id' => 'B1'],
            ['amount' => '99.00', 'id' => 'B2', 'name' => 'Unbekannt'],
        ])), 'camt.xml');

        $proposed = BankTransaction::query()->where('match_status', MatchStatus::Proposed->value)->firstOrFail();
        $unknown = BankTransaction::query()->where('match_status', MatchStatus::Unmatched->value)->firstOrFail();
        $match = app(MatchBankTransaction::class);

        expect($proposed->proposed_invoice_id)->toBe($invoice->id)
            ->and($proposed->counterparty)->toBe('Anna Muster');

        $match->book($proposed, $invoice);
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);

        $match->ignore($unknown);
        expect($unknown->refresh()->match_status)->toBe(MatchStatus::Ignored);

        $match->unassign($proposed->refresh());
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Issued)
            ->and($proposed->refresh()->match_status)->toBe(MatchStatus::Unmatched);
    });
});

it('refuses a statement of another account', function () {
    asTenant($this->tenant, function () {
        expect(fn () => app(ImportBankStatement::class)($this->account, statementFile(camt054('CH5604835012345678009', [['amount' => '1.00', 'id' => 'X']])), 'x.xml'))
            ->toThrow(BusinessRuleException::class, 'This statement is for account');
    });
});

it('reads camt.053 statements and rejects other files', function () {
    $camt053 = '<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.04"><BkToCstmrStmt><Stmt><Acct><Id><IBAN>CH2106300505282032675</IBAN></Id></Acct>'
        .'<Ntry><Amt Ccy="CHF">150.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-10-01</Dt></BookgDt><AcctSvcrRef>S1</AcctSvcrRef><AddtlNtryInf>Einzahlung</AddtlNtryInf></Ntry>'
        .'</Stmt></BkToCstmrStmt></Document>';

    $parsed = CamtParser::parse($camt053);

    expect($parsed['iban'])->toBe('CH2106300505282032675')
        ->and($parsed['entries'])->toHaveCount(1)
        ->and($parsed['entries'][0]['amount_rp'])->toBe(15_000)
        ->and($parsed['entries'][0]['remittance'])->toBe('Einzahlung')
        ->and(fn () => CamtParser::parse('<html></html>'))->toThrow(BusinessRuleException::class);
});
