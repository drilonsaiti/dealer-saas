<?php

use App\Domain\Documents\Models\Document;
use App\Domain\Inbox\Actions\SaveMailbox;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Invoicing\Actions\CreateInvoiceFromSale;
use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Portal\Actions\IssuePortalLink;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Role;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Customer portal (Phase 4): the buyer sees status, own documents, invoices and warranty
 * behind a personal link, and can upload documents. Nothing internal is visible.
 */

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();
    Carbon::setTestNow('2026-10-10 10:00');
    $this->tenant = makeDealer(['slug' => 'aziri', 'name' => 'Aziri Automobile']);
    $this->admin = makeMember($this->tenant, Role::Administrator);
    $this->actingAs($this->admin);

    $this->sale = asTenant($this->tenant, function () {
        BankAccount::factory()->create(['is_default' => true]);
        $sale = app(ContractSale::class)(reservedSale()->stockCycle, []);
        app(IssueInvoice::class)(app(CreateInvoiceFromSale::class)($sale, InvoiceType::Final));
        storeDoc('purchase_contract', '%PDF-1.4 dealer purchase', ['original_name' => 'ankauf.pdf', 'title' => 'Ankaufsvertrag intern'], [$sale->stockCycle]);
        storeDoc('buyer_identity', '%PDF-1.4 id', ['original_name' => 'ausweis.pdf', 'title' => 'Ausweis Käufer'], [$sale]);

        return $sale->refresh();
    });
});

afterEach(fn () => Carbon::setTestNow());

it('shows the buyer status, invoice and own documents, never internal or sensitive files', function () {
    [, $url] = asTenant($this->tenant, fn () => app(IssuePortalLink::class)($this->sale));
    auth()->logout();

    $page = $this->get($url)->assertOk();

    $page->assertSee('BMW X3 30i')
        ->assertSee('Anna Muster')
        ->assertSee('Contrat signé') // in the buyer's language
        ->assertSee(asTenant($this->tenant, fn () => Invoice::query()->where('sale_id', $this->sale->id)->sole()->number))
        ->assertDontSee('Ankaufsvertrag intern')
        ->assertDontSee('Ausweis Käufer');

    // An internal document cannot be fetched even by guessing its id.
    $internal = asTenant($this->tenant, fn () => Document::query()->where('title', 'Ankaufsvertrag intern')->sole());
    $this->get($url.'/documents/'.$internal->id)->assertNotFound();

    $invoicePdf = asTenant($this->tenant, fn () => Invoice::query()->where('sale_id', $this->sale->id)->sole()->document_id);
    $this->get($url.'/documents/'.$invoicePdf)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

it('takes customer uploads into the vehicle file and refuses programs', function () {
    [, $url] = asTenant($this->tenant, fn () => app(IssuePortalLink::class)($this->sale));
    auth()->logout();

    $this->post($url.'/upload', ['note' => 'Versicherungsnachweis', 'file' => UploadedFile::fake()->createWithContent('police.pdf', '%PDF-1.4 versicherung')])
        ->assertRedirect()->assertSessionHas('status');
    $this->post($url.'/upload', ['file' => UploadedFile::fake()->createWithContent('rechnung.pdf.exe', 'MZ evil')])
        ->assertSessionHasErrors('file');

    asTenant($this->tenant, function () {
        $upload = Document::query()->whereHas('category', fn ($q) => $q->where('key', 'customer_upload'))->sole();

        expect($upload->title)->toBe('Versicherungsnachweis – police')
            ->and(Document::query()->linkedTo($this->sale->stockCycle)->pluck('id')->all())->toContain($upload->id);
    });

    $this->get($url)->assertSee('Versicherungsnachweis'); // the buyer sees what they sent
});

it('stops working when renewed, switched off or expired', function () {
    [, $first] = asTenant($this->tenant, fn () => app(IssuePortalLink::class)($this->sale));
    [, $second] = asTenant($this->tenant, fn () => app(IssuePortalLink::class)($this->sale, days: 30));
    auth()->logout();

    $this->get($first)->assertNotFound();
    $this->get($second)->assertOk();
    $this->get(str_replace('cp_', 'cp_x', $second))->assertNotFound();

    Carbon::setTestNow(now()->addDays(31));
    $this->get($second)->assertNotFound();
});

it('creates the link from the vehicle file and prepares the e-mail to the buyer', function () {
    asTenant($this->tenant, function () {
        app(SaveMailbox::class)(null, ['name' => 'Aziri', 'email' => 'info@aziri.ch', 'imap_host' => 'imap.example.ch', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'smtp.example.ch', 'smtp_port' => 587, 'smtp_encryption' => 'tls']);
        $this->sale->buyer->forceFill(['email' => 'anna.muster@example.ch'])->save();
    });
    useAppPanel($this->tenant, $this->admin);

    Livewire::test(ViewStockCycle::class, ['record' => $this->sale->stock_cycle_id])
        ->callAction('portal', data: ['email' => true])
        ->assertNotified();

    $draft = EmailMessage::query()->sole();
    expect($draft->to[0]['email'])->toBe('anna.muster@example.ch')
        ->and($draft->body_text)->toContain('/portal/cp_')
        ->and($draft->body_text)->toContain('Bonjour'); // the buyer's language (French)
});
