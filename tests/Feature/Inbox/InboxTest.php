<?php

use App\Domain\Documents\Models\Document;
use App\Domain\Inbox\Actions\AssignEmail;
use App\Domain\Inbox\Actions\FetchMailbox;
use App\Domain\Inbox\Actions\SaveEmailDraft;
use App\Domain\Inbox\Actions\SaveMailbox;
use App\Domain\Inbox\Actions\SendEmail;
use App\Domain\Inbox\Enums\EmailStatus;
use App\Domain\Inbox\Imap\ImapTransport;
use App\Domain\Inbox\Mime\MimeParser;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Inbox\Models\Mailbox;
use App\Domain\Inbox\Support\MailboxTransports;
use App\Domain\Parties\Models\Party;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Support\BusinessRuleException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\Fakes\FakeImapServer;
use Tests\Fakes\RecordingSmtpTransport;

/*
 * E-mail inbox (Phase 3): mail of the dealer's own mailboxes is fetched by IMAP, assigned to
 * contact and vehicle file, attachments go to the file (dangerous ones never), and replies are
 * drafts until someone clicks "Send".
 */

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['slug' => 'aziri']);
    $this->actingAs(makeMember($this->tenant, Role::Sales));

    $this->imap = new FakeImapServer;
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

    $this->mailbox = asTenant($this->tenant, fn () => app(SaveMailbox::class)(null, [
        'name' => 'Aziri Automobile', 'email' => 'info@aziri.ch', 'imap_host' => 'imap.example.ch', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'imap_username' => 'info@aziri.ch', 'imap_password' => 'secret', 'imap_folder' => 'INBOX', 'smtp_host' => 'smtp.example.ch', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    ]));
});

/**
 * A realistic customer mail: encoded subject, Latin-1 quoted-printable text, HTML part, a PDF with
 * an RFC 2231 file name, a signature logo and a program disguised as a PDF.
 */
function customerMail(array $o = []): string
{
    $o += ['id' => 'abc-1@example.ch', 'from' => '=?UTF-8?Q?Anna_M=C3=BCller?= <Anna.Mueller@Example.ch>', 'text' => 'Guten Tag, anbei der Fahrzeugausweis zum Wagen 123.456.789. Gr=FCsse', 'extra' => ''];
    $pdf = base64_encode("%PDF-1.4\n% fake ausweis ".$o['id']."\n%%EOF");
    $exe = base64_encode('MZ'.str_repeat("\0", 64).'This program cannot be run in DOS mode');
    $logo = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

    return implode("\r\n", [
        'Return-Path: <anna.mueller@example.ch>',
        'From: '.$o['from'],
        'To: Aziri Automobile <info@aziri.ch>',
        'Subject: =?UTF-8?B?'.base64_encode('Ausweis für den Golf – bitte prüfen').'?=',
        'Date: Fri, 09 Oct 2026 14:05:00 +0200',
        'Message-ID: <'.$o['id'].'>',
        ...($o['extra'] !== '' ? [$o['extra']] : []),
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="mix"',
        '',
        'This is a multi-part message.',
        '--mix',
        'Content-Type: multipart/alternative; boundary="alt"',
        '',
        '--alt',
        'Content-Type: text/plain; charset=ISO-8859-1',
        'Content-Transfer-Encoding: quoted-printable',
        '',
        $o['text'],
        '--alt',
        'Content-Type: text/html; charset=UTF-8',
        '',
        '<p>Guten Tag</p><script>alert(1)</script>',
        '--alt--',
        '--mix',
        'Content-Type: application/pdf; name="ausweis.pdf"',
        "Content-Disposition: attachment; filename*=UTF-8''Fahrzeugausweis%20M%C3%BCller.pdf",
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split($pdf, 76, "\r\n"),
        '--mix',
        'Content-Type: image/png; name="logo.png"',
        'Content-Disposition: inline; filename="logo.png"',
        'Content-ID: <logo@example>',
        'Content-Transfer-Encoding: base64',
        '',
        $logo,
        '--mix',
        'Content-Type: application/pdf; name="rechnung.pdf.exe"',
        'Content-Disposition: attachment; filename="rechnung.pdf.exe"',
        'Content-Transfer-Encoding: base64',
        '',
        $exe,
        '--mix--',
        '',
    ]);
}

it('fetches new mail, assigns contact and vehicle file, files the PDF and quarantines the program', function () {
    [$party, $cycle] = asTenant($this->tenant, function () {
        $party = Party::factory()->create(['email' => 'anna.mueller@example.ch']);
        $cycle = StockCycle::factory()->for(Vehicle::factory()->state(['stammnummer' => '123456789']))->create();

        return [$party, $cycle];
    });

    $this->imap->add(customerMail());
    $result = asTenant($this->tenant, fn () => app(FetchMailbox::class)($this->mailbox));

    expect($result)->toBe(['new' => 1, 'error' => null])
        ->and($this->imap->commands)->toContain('EXAMINE "INBOX"') // read-only: nothing marked or deleted
        ->and(collect($this->imap->commands)->filter(fn ($c) => str_contains((string) $c, 'BODY.PEEK[]')))->toHaveCount(1);

    asTenant($this->tenant, function () use ($party, $cycle) {
        $mail = EmailMessage::query()->sole();

        expect($mail->subject)->toBe('Ausweis für den Golf – bitte prüfen')
            ->and($mail->from_address)->toBe('anna.mueller@example.ch')
            ->and($mail->from_name)->toBe('Anna Müller')
            ->and($mail->body_text)->toContain('Grüsse')
            ->and($mail->sent_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-09 12:05')
            ->and($mail->party_id)->toBe($party->id)
            ->and($mail->stock_cycle_id)->toBe($cycle->id)
            ->and($mail->matched_by)->toBe('stammnummer')
            ->and($mail->quarantined)->toHaveCount(1)
            ->and($mail->quarantined[0]['name'])->toBe('rechnung.pdf.exe');

        $documents = $mail->attachments();
        expect($documents)->toHaveCount(1) // the logo is skipped, the program refused
            ->and($documents->first()->currentVersion->original_name)->toBe('Fahrzeugausweis Müller.pdf')
            ->and($documents->first()->category->key)->toBe('correspondence')
            ->and(Document::query()->linkedTo($cycle)->pluck('id')->all())->toContain($documents->first()->id);
    });

    // Next run: nothing new; a new message arrives and only it is fetched.
    expect(asTenant($this->tenant, fn () => app(FetchMailbox::class)($this->mailbox->refresh()))['new'])->toBe(0);
    $this->imap->add(customerMail(['id' => 'abc-2@example.ch', 'from' => 'unknown@example.org', 'text' => 'Ist der Wagen WVWZZZAUZMW000777 noch da?']));
    expect(asTenant($this->tenant, fn () => app(FetchMailbox::class)($this->mailbox->refresh()))['new'])->toBe(1)
        ->and(asTenant($this->tenant, fn () => EmailMessage::query()->count()))->toBe(2);
});

it('reads a renumbered folder again without storing duplicates', function () {
    $this->imap->add(customerMail());
    asTenant($this->tenant, fn () => app(FetchMailbox::class)($this->mailbox));

    $this->imap->uidValidity = 2002;
    $this->imap->messages = [5 => customerMail()];
    $this->imap->add(customerMail(['id' => 'new-1@example.ch']));

    expect(asTenant($this->tenant, fn () => app(FetchMailbox::class)($this->mailbox->refresh())))->toBe(['new' => 1, 'error' => null])
        ->and(asTenant($this->tenant, fn () => [$this->mailbox->refresh()->uid_validity, $this->mailbox->last_uid]))->toBe([2002, 6]);
});

it('keeps a login error on the mailbox and stores nothing', function () {
    $this->imap->password = 'changed';
    $this->imap->add(customerMail());

    $result = asTenant($this->tenant, fn () => app(FetchMailbox::class)($this->mailbox));

    expect($result['error'])->toContain('refused the login')
        ->and(asTenant($this->tenant, fn () => $this->mailbox->refresh()->last_error))->toContain('Invalid credentials')
        ->and(asTenant($this->tenant, fn () => EmailMessage::query()->count()))->toBe(0);
});

it('saves a reply as draft and sends it only on request, in the same thread, with a document of the file', function () {
    $this->imap->add(customerMail());

    [$mail, $draft] = asTenant($this->tenant, function () {
        StockCycle::factory()->for(Vehicle::factory()->state(['stammnummer' => '123456789']))->create();
        app(FetchMailbox::class)($this->mailbox);
        $mail = EmailMessage::query()->sole();
        $contract = storeDoc('purchase_contract', '%PDF-1.4 contract', ['original_name' => 'kaufvertrag.pdf'], [$mail->stockCycle]);

        $draft = app(SaveEmailDraft::class)->reply($mail, ['body' => "Danke, erhalten.\n\nFreundliche Grüsse", 'document_ids' => [$contract->id]]);

        return [$mail, $draft];
    });

    expect($draft->status)->toBe(EmailStatus::Draft)
        ->and($draft->subject)->toBe('Re: Ausweis für den Golf – bitte prüfen')
        ->and($draft->to)->toBe([['email' => 'anna.mueller@example.ch', 'name' => null]])
        ->and($this->smtp->sent)->toBe([]); // nothing leaves without "Send"

    asTenant($this->tenant, fn () => app(SendEmail::class)($draft));

    $sent = $this->smtp->sent[0];
    expect($sent->getFrom()[0]->getAddress())->toBe('info@aziri.ch')
        ->and($sent->getTo()[0]->getAddress())->toBe('anna.mueller@example.ch')
        ->and($sent->getHeaders()->get('In-Reply-To')->getBodyAsString())->toBe('<abc-1@example.ch>')
        ->and($sent->getTextBody())->toContain('Danke, erhalten.')
        ->and($sent->getAttachments()[0]->getFilename())->toBe('kaufvertrag.pdf');

    asTenant($this->tenant, function () use ($draft, $mail) {
        expect($draft->refresh()->status)->toBe(EmailStatus::Sent)
            ->and($draft->message_id)->toEndWith('@aziri.ch')
            ->and($mail->refresh()->handled_at)->not->toBeNull();

        expect(fn () => app(SendEmail::class)($draft))->toThrow(BusinessRuleException::class);
    });

    // The customer answers our reply: it joins the same vehicle file by the thread.
    $this->imap->add(customerMail(['id' => 'abc-3@example.ch', 'from' => 'anna.privat@example.org', 'text' => 'Merci', 'extra' => 'In-Reply-To: <'.asTenant($this->tenant, fn () => $draft->refresh()->message_id).'>']));
    asTenant($this->tenant, function () use ($mail) {
        app(FetchMailbox::class)($this->mailbox->refresh());
        $answer = EmailMessage::query()->where('message_id', 'abc-3@example.ch')->sole();

        expect($answer->stock_cycle_id)->toBe($mail->stock_cycle_id);
    });
});

it('marks a failed send and keeps the draft', function () {
    $this->smtp->fail = '535 Authentication failed';

    $draft = asTenant($this->tenant, function () {
        $this->imap->add(customerMail());
        app(FetchMailbox::class)($this->mailbox);

        return app(SaveEmailDraft::class)->reply(EmailMessage::query()->sole(), ['body' => 'Hallo']);
    });

    expect(fn () => asTenant($this->tenant, fn () => app(SendEmail::class)($draft)))->toThrow(BusinessRuleException::class, '535');
    expect(asTenant($this->tenant, fn () => $draft->refresh()->status))->toBe(EmailStatus::Failed)
        ->and($draft->error)->toContain('535');
});

it('moves the attachments when someone assigns the e-mail to another file', function () {
    $this->imap->add(customerMail(['text' => 'ohne Nummer']));

    asTenant($this->tenant, function () {
        app(FetchMailbox::class)($this->mailbox);
        $mail = EmailMessage::query()->sole();
        $cycle = StockCycle::factory()->create();

        expect($mail->stock_cycle_id)->toBeNull();

        app(AssignEmail::class)($mail, null, $cycle->id);

        expect(Document::query()->linkedTo($cycle)->count())->toBe(1)
            ->and($mail->refresh()->matched_by)->toBe('manual');
    });
});

it('parses file names, charsets and plain messages robustly', function () {
    $parser = new MimeParser;

    $plain = $parser->parse("From: test@example.ch\nSubject: =?ISO-8859-1?Q?Offerte_f=FCr_BMW?=\nContent-Type: text/plain; charset=windows-1252\n\nPreis: 12\x80 000\n");
    expect($plain->subject)->toBe('Offerte für BMW')
        ->and($plain->text)->toContain('12€ 000')
        ->and($plain->from)->toBe(['email' => 'test@example.ch', 'name' => null])
        ->and($plain->messageId)->toBeNull();

    $split = $parser->parse(implode("\r\n", [
        'From: "Garage, Muster" <info@muster.ch>', 'Content-Type: multipart/mixed; boundary=b1', '', '--b1',
        'Content-Type: text/html; charset=utf-8', '', '<p>Hallo<br>Welt</p>', '--b1',
        'Content-Type: application/octet-stream', "Content-Disposition: attachment; filename*0*=UTF-8''Off%C3%A9rte; filename*1*=%20Nr.%201.pdf", 'Content-Transfer-Encoding: base64', '', base64_encode('%PDF-1.4'), '--b1--',
    ]));
    expect($split->from)->toBe(['email' => 'info@muster.ch', 'name' => 'Garage, Muster'])
        ->and($split->text)->toBe("Hallo\nWelt")
        ->and($split->attachments[0]->filename)->toBe('Offérte Nr. 1.pdf')
        ->and($split->attachments[0]->content)->toBe('%PDF-1.4');
});
