<?php

use App\Domain\Chat\Actions\SendWhatsApp;
use App\Domain\Chat\Models\ChatMessage;
use App\Domain\Documents\Models\Document;
use App\Domain\Integrations\Actions\SaveIntegrationAccount;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Parties\Models\Party;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Filament\App\Resources\ChatMessages\Pages\ListChatMessages;
use App\Filament\App\Resources\ChatMessages\Pages\ViewChatMessage;
use App\Support\BusinessRuleException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * WhatsApp Business (Phase 4): customer messages arrive by signed webhook and are matched to
 * contact and vehicle file; replies only within WhatsApp's 24-hour window. Meta is faked.
 */

beforeEach(function () {
    app()->setLocale('en');
    Carbon::setTestNow('2026-10-10 10:00');
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/v21.0/PHONE-1/messages' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.OUT1']]]),
        'graph.facebook.com/v21.0/MEDIA-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/media-1', 'mime_type' => 'image/jpeg']),
        'lookaside.fbsbx.com/*' => Http::response("\xFF\xD8\xFF\xE0 jpeg ".uniqid(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->tenant = makeDealer(['slug' => 'aziri']);
    $this->sales = makeMember($this->tenant, Role::Sales);
    $this->actingAs($this->sales);

    [$this->account, $this->party, $this->cycle] = asTenant($this->tenant, fn () => [
        app(SaveIntegrationAccount::class)(IntegrationAccount::WHATSAPP, ['phone_number_id' => 'PHONE-1', 'access_token' => 'tok', 'app_secret' => 'app-secret'], [], true),
        Party::factory()->create(['first_name' => 'Marco', 'last_name' => 'Bianchi', 'mobile' => '079 123 45 67']),
        StockCycle::factory()->for(Vehicle::factory()->state(['stammnummer' => '123456789']))->create(),
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function waPayload(array $messages = [], array $statuses = []): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp',
        'contacts' => [['wa_id' => '41791234567', 'profile' => ['name' => 'Marco B.']]],
        'messages' => $messages,
        'statuses' => $statuses,
    ]]]]]];
}

function postWebhook(object $test, IntegrationAccount $account, array $payload, ?string $secret = 'app-secret')
{
    $body = json_encode($payload);

    return $test->call('POST', '/api/webhooks/whatsapp/'.$account->id, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
    ], $body);
}

it('answers Meta\'s verification only with the right verify token', function () {
    $token = $this->account->setting('verify_token');
    expect($token)->toHaveLength(32);

    $this->get("/api/webhooks/whatsapp/{$this->account->id}?hub.mode=subscribe&hub.verify_token={$token}&hub.challenge=12345")
        ->assertOk()->assertSee('12345');
    $this->get("/api/webhooks/whatsapp/{$this->account->id}?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1")->assertForbidden();
});

it('stores signed customer messages once, matched to contact and vehicle file, with photos in the file', function () {
    $payload = waPayload([
        ['from' => '41791234567', 'id' => 'wamid.IN1', 'timestamp' => (string) now()->subMinutes(5)->timestamp, 'type' => 'text', 'text' => ['body' => 'Ist der Wagen 123.456.789 noch zu haben?']],
        ['from' => '41791234567', 'id' => 'wamid.IN2', 'timestamp' => (string) now()->subMinutes(4)->timestamp, 'type' => 'image', 'image' => ['id' => 'MEDIA-1', 'mime_type' => 'image/jpeg', 'caption' => 'Fahrzeugausweis']],
    ]);

    postWebhook($this, $this->account, $payload)->assertOk();
    postWebhook($this, $this->account, $payload)->assertOk(); // Meta retries: nothing doubles
    postWebhook($this, $this->account, $payload, 'forged')->assertUnauthorized();

    asTenant($this->tenant, function () {
        $messages = ChatMessage::query()->orderBy('created_at')->get();

        expect($messages)->toHaveCount(2)
            ->and($messages[0]->phone)->toBe('+41791234567')
            ->and($messages[0]->contact_name)->toBe('Marco B.')
            ->and($messages[0]->party_id)->toBe($this->party->id)
            ->and($messages[0]->stock_cycle_id)->toBe($this->cycle->id)
            ->and($messages[1]->stock_cycle_id)->toBe($this->cycle->id) // follows the conversation
            ->and($messages[1]->body)->toBe('Fahrzeugausweis')
            ->and($messages[1]->document_id)->not->toBeNull()
            ->and(Document::query()->linkedTo($this->cycle)->pluck('id')->all())->toContain($messages[1]->document_id);
    });

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://graph.facebook.com/v21.0/MEDIA-1' && $r->header('Authorization')[0] === 'Bearer tok');
});

it('replies within the 24-hour window and follows the delivery status', function () {
    postWebhook($this, $this->account, waPayload([['from' => '41791234567', 'id' => 'wamid.IN1', 'timestamp' => (string) now()->subHours(2)->timestamp, 'type' => 'text', 'text' => ['body' => 'Hallo']]]))->assertOk();

    $sent = asTenant($this->tenant, fn () => app(SendWhatsApp::class)('+41791234567', 'Grüezi Herr Bianchi, ja, der Wagen ist noch da.'));

    expect($sent->status)->toBe('sent')->and($sent->external_id)->toBe('wamid.OUT1')->and($sent->party_id)->toBe($this->party->id);
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/PHONE-1/messages') && $r['to'] === '41791234567' && $r['type'] === 'text' && str_contains($r['text']['body'], 'Grüezi'));

    postWebhook($this, $this->account, waPayload([], [['id' => 'wamid.OUT1', 'status' => 'read'], ['id' => 'wamid.OUT1', 'status' => 'delivered']]))->assertOk();
    expect(asTenant($this->tenant, fn () => $sent->refresh()->status))->toBe('read'); // a late "delivered" does not go back

    Carbon::setTestNow(now()->addHours(23));
    expect(fn () => asTenant($this->tenant, fn () => app(SendWhatsApp::class)('+41791234567', 'Noch Fragen?')))->toThrow(BusinessRuleException::class, '24 hours');
});

it('keeps a refused message as failed with the reason', function () {
    Http::fake(['graph.facebook.com/v21.0/PHONE-2/messages' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 401)]);
    asTenant($this->tenant, fn () => $this->account->forceFill(['credentials' => ['phone_number_id' => 'PHONE-2', 'access_token' => 'old', 'app_secret' => 'app-secret']])->save());
    postWebhook($this, asTenant($this->tenant, fn () => $this->account->refresh()), waPayload([['from' => '41791234567', 'id' => 'wamid.IN9', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Hallo']]]))->assertOk();

    expect(fn () => asTenant($this->tenant, fn () => app(SendWhatsApp::class)('+41791234567', 'Test')))->toThrow(BusinessRuleException::class, 'Invalid OAuth');
    expect(asTenant($this->tenant, fn () => ChatMessage::query()->where('direction', 'out')->sole()->status))->toBe('failed');
});

it('lists one row per conversation, marks it read and replies from the conversation page', function () {
    postWebhook($this, $this->account, waPayload([
        ['from' => '41791234567', 'id' => 'wamid.A', 'timestamp' => (string) now()->subMinutes(10)->timestamp, 'type' => 'text', 'text' => ['body' => 'Erste Nachricht']],
        ['from' => '41791234567', 'id' => 'wamid.B', 'timestamp' => (string) now()->subMinutes(9)->timestamp, 'type' => 'text', 'text' => ['body' => 'Zweite Nachricht']],
    ]))->assertOk();
    useAppPanel($this->tenant, $this->sales);

    $latest = ChatMessage::query()->where('external_id', 'wamid.B')->sole();

    Livewire::test(ListChatMessages::class)
        ->assertCanSeeTableRecords([$latest])
        ->assertCanNotSeeTableRecords(ChatMessage::query()->where('external_id', 'wamid.A')->get());

    Livewire::test(ViewChatMessage::class, ['record' => $latest->getRouteKey()])
        ->assertSee('Erste Nachricht')
        ->assertSee('Zweite Nachricht')
        ->callAction('reply', data: ['body' => 'Gerne!'])
        ->assertNotified(__('Message sent.'));

    expect(ChatMessage::query()->whereNull('read_at')->where('direction', 'in')->count())->toBe(0)
        ->and(ChatMessage::query()->where('direction', 'out')->sole()->body)->toBe('Gerne!');
});
