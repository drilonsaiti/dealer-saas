<?php

use App\Domain\Inbox\Actions\SaveMailbox;
use App\Domain\Inbox\Enums\EmailStatus;
use App\Domain\Inbox\Imap\ImapTransport;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Inbox\Models\Mailbox;
use App\Domain\Inbox\Support\MailboxTransports;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\EmailMessages\Pages\ListEmailMessages;
use App\Filament\App\Resources\EmailMessages\Pages\ViewEmailMessage;
use App\Filament\App\Resources\Mailboxes\Pages\ManageMailboxes;
use Livewire\Livewire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\Fakes\FakeImapServer;
use Tests\Fakes\RecordingSmtpTransport;

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['slug' => 'aziri']);
    $this->admin = makeMember($this->tenant, Role::Administrator);

    $this->imap = new FakeImapServer('verkauf@aziri.ch', 'pw-1');
    app()->instance(ImapTransport::class, $this->imap);
    $this->smtp = new RecordingSmtpTransport;
    app()->instance(MailboxTransports::class, new class($this->smtp) extends MailboxTransports
    {
        public function __construct(private readonly TransportInterface $transport) {}

        public function for(Mailbox $mailbox): TransportInterface
        {
            return $this->transport;
        }
    });
});

function simpleMail(string $id, string $subject, string $body = 'Hallo', string $attachment = ''): string
{
    $lines = ['From: Peter Keller <peter@example.ch>', 'To: verkauf@aziri.ch', "Subject: {$subject}", 'Date: Sat, 10 Oct 2026 08:00:00 +0200', "Message-ID: <{$id}>", 'MIME-Version: 1.0'];

    if ($attachment === '') {
        return implode("\r\n", [...$lines, 'Content-Type: text/plain; charset=utf-8', '', $body, '']);
    }

    return implode("\r\n", [...$lines, 'Content-Type: multipart/mixed; boundary=x', '', '--x', 'Content-Type: text/plain; charset=utf-8', '', $body, '--x',
        "Content-Type: application/pdf; name=\"{$attachment}\"", "Content-Disposition: attachment; filename=\"{$attachment}\"", 'Content-Transfer-Encoding: base64', '', base64_encode('%PDF-1.4 '.$id), '--x--', '']);
}

it('sets up a mailbox, fetches, reads, assigns and answers in the inbox', function () {
    useAppPanel($this->tenant, $this->admin);

    Livewire::test(ManageMailboxes::class)
        ->callAction('create', data: [
            'name' => 'Verkauf', 'email' => 'Verkauf@Aziri.ch', 'imap_host' => 'imap.example.ch', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'verkauf@aziri.ch', 'imap_password' => 'pw-1', 'imap_folder' => 'INBOX',
            'smtp_host' => 'smtp.example.ch', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $mailbox = Mailbox::query()->sole();
    expect($mailbox->email)->toBe('verkauf@aziri.ch')->and($mailbox->secret('imap_password'))->toBe('pw-1');

    $this->imap->add(simpleMail('m1@example.ch', 'Probefahrt Samstag?'));

    Livewire::test(ManageMailboxes::class)
        ->callTableAction('test', $mailbox)
        ->assertNotified(__('Connection works.'))
        ->callTableAction('fetch', $mailbox)
        ->assertNotified(__(':count new e-mail(s).', ['count' => 1]));

    $mail = EmailMessage::query()->sole();

    Livewire::test(ListEmailMessages::class)->assertCanSeeTableRecords([$mail]);

    $cycle = StockCycle::factory()->create();

    Livewire::test(ViewEmailMessage::class, ['record' => $mail->getRouteKey()])
        ->assertSee('Probefahrt Samstag?')
        ->assertSee('Hallo')
        ->callAction('assign', data: ['stock_cycle_id' => $cycle->id])
        ->assertHasNoActionErrors()
        ->callAction('reply', data: ['to' => 'peter@example.ch', 'subject' => 'Re: Probefahrt Samstag?', 'body' => 'Gerne um 10 Uhr.'])
        ->assertHasNoActionErrors();

    expect($mail->refresh()->read_at)->not->toBeNull()
        ->and($mail->stock_cycle_id)->toBe($cycle->id)
        ->and($this->smtp->sent)->toBe([]);

    $draft = EmailMessage::query()->where('direction', 'out')->sole();
    expect($draft->status)->toBe(EmailStatus::Draft)->and($draft->stock_cycle_id)->toBe($cycle->id);

    Livewire::test(ListEmailMessages::class)
        ->filterTable('view', 'drafts')
        ->assertCanSeeTableRecords([$draft]);

    Livewire::test(ViewEmailMessage::class, ['record' => $draft->getRouteKey()])
        ->callAction('send')
        ->assertNotified(__('E-mail sent.'));

    expect($draft->refresh()->status)->toBe(EmailStatus::Sent)
        ->and($this->smtp->sent)->toHaveCount(1)
        ->and($mail->refresh()->handled_at)->not->toBeNull();

    // Handled mail leaves the "open" view.
    Livewire::test(ListEmailMessages::class)->assertCanNotSeeTableRecords([$mail]);
});

it('fetches all dealers in the scheduled command', function () {
    asTenant($this->tenant, fn () => app(SaveMailbox::class)(null, [
        'name' => 'Verkauf', 'email' => 'verkauf@aziri.ch', 'imap_host' => 'imap.example.ch', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'imap_username' => 'verkauf@aziri.ch', 'imap_password' => 'pw-1', 'imap_folder' => 'INBOX',
    ]));
    $this->imap->add(simpleMail('m2@example.ch', 'Frage', 'Hallo', 'offerte.pdf'));

    $this->artisan('mail:fetch')->expectsOutputToContain('1 new')->assertSuccessful();

    expect(asTenant($this->tenant, fn () => EmailMessage::query()->sole()->attachments()->count()))->toBe(1);
});

it('quarantines attachments when the configured virus scanner is not reachable', function () {
    config(['dealer.mail.clamav' => 'tcp://127.0.0.1:1']);
    asTenant($this->tenant, fn () => app(SaveMailbox::class)(null, [
        'name' => 'Verkauf', 'email' => 'verkauf@aziri.ch', 'imap_host' => 'imap.example.ch', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'imap_username' => 'verkauf@aziri.ch', 'imap_password' => 'pw-1', 'imap_folder' => 'INBOX',
    ]));
    $this->imap->add(simpleMail('m3@example.ch', 'Offerte', 'Hallo', 'offerte.pdf'));

    $this->artisan('mail:fetch')->assertSuccessful();

    asTenant($this->tenant, function () {
        $mail = EmailMessage::query()->sole();

        expect($mail->attachments())->toHaveCount(0)
            ->and($mail->quarantined[0]['name'])->toBe('offerte.pdf')
            ->and($mail->quarantined[0]['reason'])->toContain('virus scanner');
    });
});

it('keeps mail to sales, accounting and administrators and mailboxes to administrators', function () {
    $readOnly = makeMember($this->tenant, Role::ReadOnly);
    $sales = makeMember($this->tenant, Role::Sales);

    asTenant($this->tenant, fn () => expect($readOnly->can('viewAny', EmailMessage::class))->toBeFalse()
        ->and($sales->can('viewAny', EmailMessage::class))->toBeTrue()
        ->and($sales->can('viewAny', Mailbox::class))->toBeFalse());
});
