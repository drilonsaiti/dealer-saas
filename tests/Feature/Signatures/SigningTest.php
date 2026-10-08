<?php

use App\Domain\Documents\Actions\AddDocumentVersion;
use App\Domain\Documents\Actions\GenerateContract;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Generation\DocumentRenderer;
use App\Domain\Documents\Models\Document;
use App\Domain\Signatures\Actions\CancelSigning;
use App\Domain\Signatures\Actions\RecordPaperSignature;
use App\Domain\Signatures\Actions\RecordSignature;
use App\Domain\Signatures\Actions\SendSigningCode;
use App\Domain\Signatures\Actions\StartSigning;
use App\Domain\Signatures\Enums\SignatureRequestStatus;
use App\Domain\Signatures\Enums\SigningMethod;
use App\Domain\Signatures\Mail\SignedDocumentMail;
use App\Domain\Signatures\Mail\SigningCodeMail;
use App\Domain\Signatures\Mail\SigningLinkMail;
use App\Domain\Signatures\Models\SignatureRequest;
use App\Domain\Signatures\Support\DocumentSealer;
use App\Domain\Signatures\Support\PyHankoSealer;
use App\Domain\Tenancy\Enums\Role;
use App\Filament\App\Resources\Documents\Pages\ListDocuments;
use App\Filament\App\Resources\Documents\Pages\SignDocument;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/*
 * Acceptance tests 4 (signature on iPad and by customer link) and 5 (signed contract
 * stored immutably): own simple electronic signature with evidence page and seal.
 */

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();
    Mail::fake();
    app()->bind(DocumentSealer::class, fn () => new class implements DocumentSealer
    {
        public function seal(string $path): ?array
        {
            return ['method' => 'test seal'];
        }
    });

    $this->tenant = makeDealer(['name' => 'Aziri Automobile GmbH', 'slug' => 'aziri', 'city' => 'Zollikofen']);
    $this->user = makeMember($this->tenant, Role::Sales, ['name' => 'Ardit Aziri']);
    $this->actingAs($this->user);
});

function finalisedContract(): Document
{
    return app(GenerateContract::class)(reservedSale(), 'de', null);
}

/** A drawn signature as the browser sends it. */
function drawnSignature(): string
{
    $image = imagecreatetruecolor(300, 100);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagesetthickness($image, 3);
    imageline($image, 10, 80, 290, 20, imagecolorallocate($image, 11, 31, 77));
    imageline($image, 20, 20, 280, 90, imagecolorallocate($image, 11, 31, 77));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

it('signs on the iPad: ID check, customer, then dealer, filed as a sealed locked version', function () {
    asTenant($this->tenant, function () {
        $document = finalisedContract();
        $original = $document->currentVersion;

        $request = app(StartSigning::class)($document, SigningMethod::OnDevice, [], $this->user);
        [$customer, $dealer] = $request->signers->all();

        expect($document->refresh()->status)->toBe(DocumentStatus::OutForSignature)
            ->and($customer->name)->toBe('Anna Muster')
            ->and($dealer->name)->toBe('Ardit Aziri');

        $record = app(RecordSignature::class);
        $device = ['ip' => '192.0.2.10', 'user_agent' => 'iPad Safari'];

        expect(fn () => $record($dealer, drawnSignature(), 'Zollikofen', true, $device, by: $this->user))
            ->toThrow(BusinessRuleException::class, 'Someone else has to sign first.')
            ->and(fn () => $record($customer, drawnSignature(), 'Zollikofen', true, $device, null, $this->user))
            ->toThrow(BusinessRuleException::class, 'Check the customer’s ID')
            ->and(fn () => $record($customer, 'data:image/png;base64,', 'Zollikofen', true, $device, ['doc_type' => 'id_card', 'doc_number' => 'C1234567'], $this->user))
            ->toThrow(BusinessRuleException::class, 'Please sign in the field.')
            ->and(fn () => $record($customer, drawnSignature(), 'Zollikofen', false, $device, ['doc_type' => 'id_card', 'doc_number' => 'C1234567'], $this->user))
            ->toThrow(BusinessRuleException::class, 'read and accept');

        $record($customer, drawnSignature(), 'Zollikofen', true, $device, ['doc_type' => 'id_card', 'doc_number' => 'C1234567'], $this->user);
        expect($document->refresh()->status)->toBe(DocumentStatus::OutForSignature);

        $record($dealer->refresh(), drawnSignature(), 'Zollikofen', true, $device, by: $this->user);

        $document->refresh();
        $signed = $document->currentVersion;
        $request->refresh();

        expect($document->status)->toBe(DocumentStatus::Signed)
            ->and($signed->version_no)->toBe(2)
            ->and($signed->locked_at)->not->toBeNull()
            ->and($signed->original_name)->toEndWith('_Kaufvertrag_DE_Unterschrieben.pdf')
            ->and($signed->data_snapshot['signatures']['right']['image'])->toStartWith('data:image/png;base64,') // buyer
            ->and($signed->data_snapshot['signatures']['left']['place_date'])->toStartWith('Zollikofen, ') // seller = dealer
            ->and($signed->data_snapshot['evidence']['document_sha256'])->toBe($original->sha256)
            ->and($signed->data_snapshot['evidence']['signers'][0]['identification']['doc_number'])->toBe('*****567')
            ->and($signed->data_snapshot['evidence']['signers'][0]['ip'])->toBe('192.0.2.10')
            ->and($request->status)->toBe(SignatureRequestStatus::Completed)
            ->and($request->signed_version_id)->toBe($signed->id)
            ->and($request->seal)->toBe(['method' => 'test seal'])
            ->and($customer->refresh()->identification['doc_number'])->toBe('C1234567')
            ->and($original->refresh()->sha256)->toBe(hash('sha256', $original->contents()));

        // The evidence page is part of the signed PDF, in the contract's language.
        expect(app(DocumentRenderer::class)->html($signed->data_snapshot))
            ->toContain('Nachweis der Unterschriften')
            ->toContain('Ausweis geprüft von Ardit Aziri');

        Mail::assertSent(SignedDocumentMail::class, fn (SignedDocumentMail $mail): bool => $mail->hasTo($customer->email) && $mail->locale === 'de');
    });
});

it('never changes a signed contract', function () {
    asTenant($this->tenant, function () {
        $document = finalisedContract();
        app(RecordPaperSignature::class)($document, (function () {
            $scan = tempnam(sys_get_temp_dir(), 'scan');
            file_put_contents($scan, '%PDF-1.4 signed scan');

            return $scan;
        })(), 'Kaufvertrag_unterschrieben.pdf');

        $document->refresh();
        $sale = $document->stockCycles()->first()->activeSale;

        expect($document->status)->toBe(DocumentStatus::Signed)
            ->and($document->currentVersion->locked_at)->not->toBeNull()
            ->and(fn () => app(GenerateContract::class)($sale, 'de', 'Neu'))->toThrow(BusinessRuleException::class, 'already signed')
            ->and(fn () => app(AddDocumentVersion::class)($document, __FILE__))->toThrow(BusinessRuleException::class, 'locked')
            ->and($this->user->can('update', $document))->toBeFalse()
            ->and($this->user->can('delete', $document))->toBeFalse()
            ->and(fn () => app(StartSigning::class)($document, SigningMethod::OnDevice, [], $this->user))->toThrow(BusinessRuleException::class, 'already signed');
    });
});

it('lets the customer sign by link with a one-time code, then the dealer countersigns', function () {
    $document = asTenant($this->tenant, fn () => finalisedContract());
    asTenant($this->tenant, fn () => app(StartSigning::class)($document, SigningMethod::Link, ['email' => 'anna@example.ch', 'locale' => 'fr'], $this->user));

    $url = null;
    Mail::assertSent(SigningLinkMail::class, function (SigningLinkMail $mail) use (&$url): bool {
        $url = $mail->url;

        return $mail->hasTo('anna@example.ch') && $mail->locale === 'fr';
    });
    $token = basename((string) $url);

    auth()->logout();

    $this->get(route('signing.show', $token))->assertOk()->assertSee('Kaufvertrag')->assertSee('Envoyer le code');
    $this->post(route('signing.sign', $token), ['signature' => drawnSignature(), 'place' => 'Bern', 'accepted' => '1'])
        ->assertSessionHasErrors('signature');

    $this->post(route('signing.code', $token))->assertSessionHas('status');
    $code = null;
    Mail::assertSent(SigningCodeMail::class, function (SigningCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    $this->post(route('signing.verify', $token), ['code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
    $this->post(route('signing.verify', $token), ['code' => $code])->assertSessionHasNoErrors();
    $this->post(route('signing.sign', $token), ['signature' => drawnSignature(), 'place' => 'Bern', 'accepted' => '1'], ['User-Agent' => 'Mobile Safari'])
        ->assertRedirect(route('signing.show', $token));
    $this->get(route('signing.show', $token))->assertSee('Merci, vous avez signé.');

    asTenant($this->tenant, function () use ($document) {
        $request = SignatureRequest::query()->with('signers')->firstOrFail();
        [$customer, $dealer] = $request->signers->all();

        expect($customer->identification['method'])->toBe('link_code')
            ->and($customer->user_agent)->toBe('Mobile Safari')
            ->and($document->refresh()->status)->toBe(DocumentStatus::OutForSignature);

        app(RecordSignature::class)($dealer, drawnSignature(), 'Zollikofen', true, [], by: $this->user);

        expect($document->refresh()->status)->toBe(DocumentStatus::Signed)
            ->and($document->currentVersion->data_snapshot['evidence']['signers'][0]['identification']['code_sent_to'])->toBe('anna@example.ch');
    });
});

it('blocks a code after five wrong tries', function () {
    asTenant($this->tenant, function () {
        $request = app(StartSigning::class)(finalisedContract(), SigningMethod::Link, ['email' => 'anna@example.ch'], $this->user);
        $customer = $request->signers->first();
        $send = app(SendSigningCode::class);
        $send($customer, 'Aziri');

        foreach (range(1, 5) as $try) {
            expect(fn () => $send->verify($customer->refresh(), '999999x'))->toThrow(BusinessRuleException::class);
        }

        expect(fn () => $send->verify($customer->refresh(), '123456'))->toThrow(BusinessRuleException::class, 'no longer valid');
    });
});

it('closes the link when the signing is withdrawn or has expired', function () {
    [$document, $token] = asTenant($this->tenant, function () {
        $document = finalisedContract();
        app(StartSigning::class)($document, SigningMethod::Link, ['email' => 'anna@example.ch'], $this->user);

        return [$document, null];
    });

    $url = null;
    Mail::assertSent(SigningLinkMail::class, function (SigningLinkMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });

    asTenant($this->tenant, function () use ($document) {
        app(CancelSigning::class)(SignatureRequest::query()->firstOrFail(), 'Falscher Preis');
        expect($document->refresh()->status)->toBe(DocumentStatus::Final);

        // Sent again, and this time the link runs out.
        $request = app(StartSigning::class)($document, SigningMethod::Link, ['email' => 'anna@example.ch'], $this->user);
        $request->forceFill(['expires_at' => now()->subMinute()])->save();
    });

    $this->get(route('signing.show', basename((string) $url)))->assertNotFound();

    $this->artisan('signatures:expire')->assertSuccessful();

    asTenant($this->tenant, function () use ($document) {
        expect(SignatureRequest::query()->where('status', SignatureRequestStatus::Expired->value)->count())->toBe(1)
            ->and($document->refresh()->status)->toBe(DocumentStatus::Final);
    });
});

it('starts and completes the signing from the documents screen and the signing page', function () {
    useAppPanel($this->tenant, $this->user);
    $document = finalisedContract();

    Livewire::test(ListDocuments::class)
        ->callTableAction('startSigning', $document, data: ['method' => 'on_device', 'name' => 'Anna Muster', 'locale' => 'de'])
        ->assertHasNoTableActionErrors()
        ->assertRedirect(SignDocument::getUrl(['record' => $document]));

    Livewire::test(SignDocument::class, ['record' => $document->getRouteKey()])
        ->assertSee('Anna Muster')
        ->fillForm(['doc_type' => 'passport', 'doc_number' => 'X1234567', 'id_checked' => true, 'accepted' => true, 'place' => 'Zollikofen', 'signature' => drawnSignature()])
        ->call('sign')
        ->assertHasNoFormErrors()
        ->assertRedirect(SignDocument::getUrl(['record' => $document]));

    Livewire::test(SignDocument::class, ['record' => $document->getRouteKey()])
        ->assertSee('Ardit Aziri')
        ->assertDontSee('ID document')
        ->fillForm(['accepted' => true, 'place' => 'Zollikofen', 'signature' => drawnSignature()])
        ->call('sign')
        ->assertHasNoFormErrors();

    expect($document->refresh()->status)->toBe(DocumentStatus::Signed);
});

it('seals the signed PDF with pyHanko (PAdES)', function () {
    $dir = sys_get_temp_dir().'/seal-'.uniqid();
    mkdir($dir);
    config(['dealer.signatures.seal_key' => null, 'dealer.signatures.seal_cert' => null]);
    $storage = $this->app->storagePath();
    $this->app->useStoragePath($dir);

    $pdf = $dir.'/test.pdf';
    file_put_contents($pdf, minimalPdf());

    $seal = app(PyHankoSealer::class)->seal($pdf);
    $check = Process::run(['pyhanko', 'sign', 'validate', '--pretty-print', $pdf]);

    expect($seal['method'])->toBe('PAdES')
        ->and($seal['certificate'])->toContain('document seal')
        ->and($check->output())->toContain('cryptographically sound');

    $this->app->useStoragePath($storage);
})->skip(fn () => ! Process::run(['pyhanko', '--version'])->successful(), 'pyHanko is not installed');

function minimalPdf(): string
{
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>',
    ];
    $pdf = "%PDF-1.7\n";
    $offsets = [];

    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}
