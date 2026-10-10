<?php

use App\Domain\Documents\Models\Document;
use App\Domain\Inbox\Actions\SaveMailbox;
use App\Domain\Inbox\Actions\SendEmail;
use App\Domain\Inbox\Enums\EmailStatus;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Inbox\Models\Mailbox;
use App\Domain\Inbox\Support\MailboxTransports;
use App\Domain\Parties\Models\Party;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Warranty\Actions\AddWarranty;
use App\Domain\Warranty\Actions\AttachClaimDocuments;
use App\Domain\Warranty\Actions\HandleWarrantyClaim;
use App\Domain\Warranty\Actions\SendToWarrantyProvider;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyProduct;
use App\Filament\App\Resources\Warranties\Pages\ViewWarranty;
use App\Support\BusinessRuleException;
use Livewire\Livewire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\Fakes\RecordingSmtpTransport;

/*
 * Warranty providers (NSA, MultiPart, Phase 3): without an official API, registrations and
 * claims go to the provider by e-mail, prepared as drafts in the inbox and sent by a person.
 */

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();
    $this->tenant = makeDealer(['slug' => 'aziri', 'name' => 'Aziri Automobile']);
    $this->admin = makeMember($this->tenant, Role::Administrator);
    $this->actingAs($this->admin);

    $this->smtp = new RecordingSmtpTransport;
    app()->instance(MailboxTransports::class, new class($this->smtp) extends MailboxTransports
    {
        public function __construct(private readonly TransportInterface $transport) {}

        public function for(Mailbox $mailbox): TransportInterface
        {
            return $this->transport;
        }
    });

    $this->warranty = asTenant($this->tenant, function (): Warranty {
        app(SaveMailbox::class)(null, ['name' => 'Aziri', 'email' => 'info@aziri.ch', 'imap_host' => 'imap.example.ch', 'imap_username' => 'info', 'imap_password' => 'x', 'smtp_host' => 'smtp.example.ch', 'smtp_port' => 587, 'smtp_encryption' => 'tls']);
        $nsa = Party::factory()->create(['kind' => 'company', 'company_name' => 'NSA Garantie AG', 'first_name' => null, 'last_name' => null, 'email' => 'vertrag@nsa.example.ch']);
        $product = WarrantyProduct::create(['provider_party_id' => $nsa->id, 'name' => ['de' => 'NSA Top 24'], 'duration_months' => 24, 'km_limit' => 30_000, 'deductible_rp' => 20_000, 'cost_rp' => 50_000, 'price_rp' => 90_000, 'submission' => 'email']);

        return app(AddWarranty::class)(reservedSale(), $product);
    });
});

it('prepares the registration as a PDF and an e-mail draft to the provider, sent only by a person', function () {
    $message = asTenant($this->tenant, fn () => app(SendToWarrantyProvider::class)->warranty($this->warranty));

    expect($message)->toContain('draft in the inbox');

    asTenant($this->tenant, function () {
        $warranty = $this->warranty->refresh();
        $draft = EmailMessage::query()->findOrFail($warranty->submission_email_id);

        expect($warranty->submitted_at)->not->toBeNull()
            ->and($draft->status)->toBe(EmailStatus::Draft)
            ->and($draft->to[0]['email'])->toBe('vertrag@nsa.example.ch') // provider contact's e-mail
            ->and($draft->subject)->toContain('NSA Top 24')->toContain('WBAXX110X0L123456')
            ->and($draft->body_text)->toContain('Anna Muster')
            ->and($draft->stock_cycle_id)->toBe($warranty->stock_cycle_id)
            ->and($draft->attachments()->sole()->category->key)->toBe('warranty_submission')
            ->and(Document::query()->linkedTo($warranty->stockCycle)->whereHas('category', fn ($q) => $q->where('key', 'warranty_submission'))->count())->toBe(1);

        expect($this->smtp->sent)->toBe([]);

        app(SendEmail::class)($draft);
        expect($this->smtp->sent[0]->getAttachments()[0]->getFilename())->toBe('garantie-anmeldung.pdf');
    });
});

it('sends a claim report with its photos and invoices to the provider address of the product', function () {
    asTenant($this->tenant, function () {
        $this->warranty->product->forceFill(['provider_email' => 'schaden@nsa.example.ch'])->save();
        $this->warranty->forceFill(['status' => 'active', 'starts_on' => '2026-10-01', 'policy_number' => 'NSA-55'])->save();
        $claim = app(HandleWarrantyClaim::class)->report($this->warranty->refresh(), ['occurred_on' => '2026-10-05', 'mileage' => 81_000, 'description' => 'Turbolader defekt']);

        [$photo, $name] = explode('|', fakeFile("\x89PNG photo", 'turbo.png'));
        [$invoice, $invoiceName] = explode('|', fakeFile('%PDF-1.4 KV Garage', 'kostenvoranschlag.pdf'));
        app(AttachClaimDocuments::class)($claim, [[$photo, $name], [$invoice, $invoiceName]]);

        app(SendToWarrantyProvider::class)->claim($claim);
        $draft = EmailMessage::query()->findOrFail($claim->refresh()->report_email_id);

        expect($draft->to[0]['email'])->toBe('schaden@nsa.example.ch')
            ->and($draft->subject)->toContain('NSA-55')
            ->and($draft->body_text)->toContain('Turbolader defekt')->toContain("81'000 km")
            ->and($draft->attachments()->map(fn (Document $d) => $d->currentVersion->original_name)->sort()->values()->all())
            ->toBe(['garantiefall.pdf', 'kostenvoranschlag.pdf', 'turbo.png'])
            ->and($claim->reported_at)->not->toBeNull();
    });
});

it('says what is missing: provider address, mailbox, or an own warranty', function () {
    asTenant($this->tenant, function () {
        $product = $this->warranty->product;

        $product->forceFill(['submission' => 'manual'])->save();
        expect(fn () => app(SendToWarrantyProvider::class)->warranty($this->warranty->refresh()))->toThrow(BusinessRuleException::class, 'not sent to a provider');

        $product->forceFill(['submission' => 'email'])->save();
        $product->provider->forceFill(['email' => null])->save();
        expect(fn () => app(SendToWarrantyProvider::class)->warranty($this->warranty->refresh()))->toThrow(BusinessRuleException::class, 'e-mail address of the provider');

        $product->forceFill(['provider_email' => 'x@nsa.example.ch'])->save();
        Mailbox::query()->update(['smtp_host' => null]);
        expect(fn () => app(SendToWarrantyProvider::class)->warranty($this->warranty->refresh()))->toThrow(BusinessRuleException::class, 'mailbox with SMTP');
    });
});

it('offers "Send to provider" on the warranty and shows the state', function () {
    useAppPanel($this->tenant, $this->admin);

    Livewire::test(ViewWarranty::class, ['record' => $this->warranty->getRouteKey()])
        ->assertSee(__('not yet'))
        ->callAction('sendToProvider')
        ->assertNotified();

    Livewire::test(ViewWarranty::class, ['record' => $this->warranty->getRouteKey()])
        ->assertSee(__('draft prepared :date – send it in the inbox', ['date' => now()->format('d.m.Y')]));
});
