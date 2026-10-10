<?php

use App\Domain\Ai\Actions\DraftEmailReply;
use App\Domain\Ai\Actions\WriteListingText;
use App\Domain\Ai\Models\AiRequest;
use App\Domain\Ai\Support\Assistant;
use App\Domain\Inbox\Actions\SaveMailbox;
use App\Domain\Inbox\Actions\StoreIncomingEmail;
use App\Domain\Listings\Actions\PublishListing;
use App\Domain\Listings\Actions\SaveListing;
use App\Domain\Parties\Models\Party;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Filament\App\Pages\Tenancy\EditCompanyProfile;
use App\Support\BusinessRuleException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * AI assistance (Phase 4): advert texts and reply drafts as suggestions, only when the server
 * has a key and the dealer switched it on; only the needed data is sent; every request logged.
 */

beforeEach(function () {
    app()->setLocale('en');
    config(['dealer.ai.api_key' => 'sk-test', 'dealer.ai.model' => 'claude-test']);
    Http::preventStrayRequests();
    $this->tenant = makeDealer(['slug' => 'aziri', 'name' => 'Aziri Automobile', 'phone' => '031 123 45 67', 'settings' => ['ai' => ['enabled' => true]]]);
    $this->admin = makeMember($this->tenant, Role::Administrator);
    $this->actingAs($this->admin);

    $this->cycle = asTenant($this->tenant, fn () => StockCycle::factory()->status(StockCycleStatus::ReadyForSale)
        ->for(Vehicle::factory()->state(['make' => 'Skoda', 'model' => 'Octavia', 'variant' => 'Combi 2.0 TDI 4x4', 'fuel' => 'diesel', 'power_kw' => 110, 'first_registration_on' => '2021-03-15', 'equipment' => ['Anhängerkupplung', 'Standheizung']]))
        ->create(['mileage_in' => 64_000, 'list_price_rp' => 1_890_000]));
});

function claudeAnswers(string $text): void
{
    Http::fake(['api.anthropic.com/v1/messages' => Http::response([
        'content' => [['type' => 'text', 'text' => $text]],
        'usage' => ['input_tokens' => 812, 'output_tokens' => 433],
    ])]);
}

it('suggests advert texts in all languages from the vehicle facts only', function () {
    claudeAnswers('Hier der Vorschlag: {"title": {"de": "Skoda Octavia Combi 4x4 mit Anhängerkupplung", "fr": "Skoda Octavia Combi 4x4 avec attelage", "it": "Skoda Octavia Combi 4x4 con gancio", "en": "Skoda Octavia Estate 4x4 with towbar"}, "description": {"de": "Grosszügiger Kombi mit Allradantrieb.\n\nMit Standheizung und Anhängerkupplung – ideal für den Winter. Gemäß Serviceheft gepflegt.", "fr": "Break spacieux.", "it": "Station wagon spaziosa.", "en": "Spacious estate."}, "highlights": ["4x4", "Standheizung", "<b>AHK</b>"]}');

    $texts = asTenant($this->tenant, fn () => app(WriteListingText::class)($this->cycle, '1. Hand'));

    expect($texts['title']['de'])->toBe('Skoda Octavia Combi 4x4 mit Anhängerkupplung')
        ->and($texts['description']['de'])->toBe('<p>Grosszügiger Kombi mit Allradantrieb.</p><p>Mit Standheizung und Anhängerkupplung – ideal für den Winter. Gemäss Serviceheft gepflegt.</p>')
        ->and($texts['description']['fr'])->toBe('<p>Break spacieux.</p>')
        ->and($texts['highlights'])->toBe(['4x4', 'Standheizung', 'AHK']);

    Http::assertSent(function (Request $request): bool {
        $prompt = $request['messages'][0]['content'];

        return $request->header('x-api-key')[0] === 'sk-test'
            && $request['model'] === 'claude-test'
            && str_contains($request['system'], 'never use "ß"')
            && str_contains($prompt, '"mileage_km": 64000')
            && str_contains($prompt, 'Standheizung')
            && str_contains($prompt, '1. Hand')
            && ! str_contains($prompt, '18')  // no price
            && ! str_contains($prompt, 'Aziri'); // no dealer or customer data needed
    });

    $log = asTenant($this->tenant, fn () => AiRequest::query()->sole());
    expect($log->purpose)->toBe('listing_text')
        ->and($log->input_tokens)->toBe(812)
        ->and($log->output_tokens)->toBe(433)
        ->and($log->subject_id)->toBe($this->cycle->id)
        ->and($log->user_id)->toBe($this->admin->id);
});

it('drafts a reply in the customer\'s language with the facts of the vehicle file', function () {
    claudeAnswers("Bonjour Monsieur Rochat\n\nLa Skoda est encore disponible au prix de CHF 18'900. Nous vous proposons un essai [date à proposer].\n\n\n\nMeilleures salutations\nAziri Automobile");

    $reply = asTenant($this->tenant, function () {
        $listing = app(PublishListing::class)(app(SaveListing::class)($this->cycle, ['photo_document_ids' => [attachPhoto($this->cycle)->id]]));
        Party::factory()->create(['email' => 'luc.rochat@example.ch']);
        $mailbox = app(SaveMailbox::class)(null, ['name' => 'Aziri', 'email' => 'info@aziri.ch', 'imap_host' => 'imap.example.ch', 'imap_username' => 'x', 'imap_password' => 'x']);
        $mail = app(StoreIncomingEmail::class)($mailbox, "From: Luc Rochat <luc.rochat@example.ch>\r\nSubject: Octavia {$this->cycle->refresh()->number}\r\nMessage-ID: <r1@example.ch>\r\n\r\nBonjour, la voiture est-elle encore disponible? Merci, Luc");
        $mail->forceFill(['stock_cycle_id' => $this->cycle->id])->save();

        return app(DraftEmailReply::class)($mail->refresh(), 'propose a test drive');
    });

    expect($reply)->toStartWith('Bonjour Monsieur Rochat')->not->toContain("\n\n\n");

    Http::assertSent(function (Request $request): bool {
        $prompt = $request['messages'][0]['content'];

        return str_contains($prompt, 'la voiture est-elle encore disponible')
            && str_contains($prompt, '"advertised_price"')
            && str_contains($prompt, '"customer_name": "Luc Rochat"')
            && str_contains($prompt, 'propose a test drive')
            && str_contains($request['system'], 'language of the customer');
    });
});

it('stays off without key, when the dealer switched it off, and at the monthly limit', function () {
    claudeAnswers('{}');

    asTenant($this->tenant, function () {
        expect(app(Assistant::class)->available())->toBeTrue();

        $this->tenant->forceFill(['settings' => ['ai' => ['enabled' => false]]])->save();
        expect(app(Assistant::class)->available())->toBeFalse()
            ->and(fn () => app(WriteListingText::class)($this->cycle))->toThrow(BusinessRuleException::class, 'switched off');

        $this->tenant->forceFill(['settings' => ['ai' => ['enabled' => true]]])->save();
        config(['dealer.ai.monthly_requests' => 1]);
        expect(fn () => app(WriteListingText::class)($this->cycle))->toThrow(BusinessRuleException::class, 'could not be read'); // 1st: bad answer, logged
        expect(fn () => app(WriteListingText::class)($this->cycle))->toThrow(BusinessRuleException::class, 'monthly limit');

        config(['dealer.ai.api_key' => null]);
        expect(app(Assistant::class)->available())->toBeFalse();
    });

    Http::assertSentCount(1);
});

it('logs refused requests with the reason', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401)]);

    expect(fn () => asTenant($this->tenant, fn () => app(WriteListingText::class)($this->cycle)))->toThrow(BusinessRuleException::class, 'invalid x-api-key');
    expect(asTenant($this->tenant, fn () => AiRequest::query()->sole()->status))->toBe('error');
});

it('lets the administrator switch AI on in the company profile', function () {
    useAppPanel($this->tenant, $this->admin);
    $this->tenant->forceFill(['settings' => []])->save();

    Livewire::test(EditCompanyProfile::class)
        ->assertSee(__('AI assistance'))
        ->fillForm(['settings.ai.enabled' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->tenant->refresh()->setting('ai.enabled'))->toBeTrue();
});
