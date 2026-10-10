<?php

use App\Domain\Api\Actions\IssueApiToken;
use App\Domain\Api\Models\WebhookDelivery;
use App\Domain\Api\Models\WebhookEndpoint;
use App\Domain\Api\Support\Webhooks;
use App\Domain\Listings\Actions\PublishListing;
use App\Domain\Listings\Actions\SaveListing;
use App\Domain\Listings\Enums\Availability;
use App\Domain\Listings\Mail\EnquiryReceivedMail;
use App\Domain\Listings\Models\Enquiry;
use App\Domain\Listings\Models\Listing;
use App\Domain\Parties\Models\Party;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Sales\Actions\ReserveVehicle;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Acceptance test 11 (website part): a car published in the vehicle file appears on the
 * dealer's website through the public API, follows reserved / sold, and enquiries come back.
 */

beforeEach(function () {
    app()->setLocale('en');
    Carbon::setTestNow('2026-10-10 09:00');
    Http::fake(['https://hooks.example.ch/*' => Http::response('ok', 200)]);
    $this->tenant = makeDealer(['slug' => 'aziri', 'email' => 'info@aziri.example.ch']);
    $this->actingAs(makeMember($this->tenant, Role::Administrator));
    [, $this->token] = asTenant($this->tenant, fn () => app(IssueApiToken::class)('Website', ['listings:read', 'enquiries:write']));
});

afterEach(fn () => Carbon::setTestNow());

/** A ready-for-sale VW Golf with two photos, published. */
function publishedGolf(array $listing = []): Listing
{
    $cycle = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)
        ->for(Vehicle::factory()->state(['make' => 'VW', 'model' => 'Golf', 'variant' => '2.0 TDI', 'fuel' => 'diesel', 'body_type' => 'hatchback', 'power_kw' => 110, 'first_registration_on' => '2021-04-12', 'equipment' => ['Navigation', 'Sitzheizung']]))
        ->create(['mileage_in' => 48_000, 'list_price_rp' => 2_190_000]);
    attachPhoto($cycle);
    attachPhoto($cycle);

    return app(PublishListing::class)(app(SaveListing::class)($cycle, $listing));
}

function api(string $token): array
{
    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/vnd.api+json'];
}

it('publishes a car and shows it on the website API with photos, then reserved and sold (acceptance test 11)', function () {
    $listing = asTenant($this->tenant, fn () => publishedGolf([
        'title' => ['de' => 'VW Golf 2.0 TDI, 1. Hand', 'fr' => 'VW Golf 2.0 TDI, première main'],
        'description' => ['de' => '<p onclick="x()">Gepflegt <script>alert(1)</script><strong>ab MFK</strong></p>'],
        'highlights' => ['1. Hand', 'Serviceheft'],
    ]));

    asTenant($this->tenant, fn () => expect($listing->stockCycle->refresh()->status)->toBe(StockCycleStatus::Listed)
        ->and($listing->getTranslation('description', 'de'))->toBe('<p>Gepflegt alert(1)<strong>ab MFK</strong></p>')
        ->and($listing->getTranslation('title', 'it'))->toBe('VW Golf 2.0 TDI, 1. Hand')); // missing language → German

    $list = $this->getJson('/api/v1/vehicles', api($this->token))->assertOk()->json();
    $car = $list['data'][0];

    expect($list['data'])->toHaveCount(1)
        ->and($car['type'])->toBe('vehicles')
        ->and($car['id'])->toBe($listing->id)
        ->and($car['attributes']['availability'])->toBe('available')
        ->and($car['attributes']['price'])->toBe(['amount' => '21900.00', 'currency' => 'CHF'])
        ->and($car['attributes']['first_registration'])->toBe('2021-04')
        ->and($car['attributes']['mileage_km'])->toBe(48_000)
        ->and($car['attributes']['power_hp'])->toBe(150)
        ->and($car['attributes']['equipment'])->toBe(['Navigation', 'Sitzheizung'])
        ->and($car['attributes']['photos'])->toHaveCount(2)
        ->and($car['attributes']['photos'][0]['cover'])->toBeTrue();

    // Photos work without the token (signed URL), and only while published.
    $this->get($car['attributes']['photos'][0]['url'])->assertOk()->assertHeader('Cache-Control', 'max-age=86400, public');
    $this->get(preg_replace('/signature=\w+/', 'signature=forged', $car['attributes']['photos'][0]['url']))->assertForbidden();

    expect($this->getJson('/api/v1/vehicles/'.$listing->id.'?lang=fr', api($this->token))->json('data.attributes.title'))->toBe('VW Golf 2.0 TDI, première main');

    // Reserved and sold follow the vehicle file.
    asTenant($this->tenant, fn () => app(ReserveVehicle::class)($listing->stockCycle->refresh(), ['buyer_party_id' => Party::factory()->create()->id, 'price_rp' => 2_150_000]));
    expect($this->getJson('/api/v1/vehicles/'.$listing->id, api($this->token))->json('data.attributes.availability'))->toBe('reserved');

    asTenant($this->tenant, fn () => app(ContractSale::class)($listing->stockCycle->refresh(), []));
    expect($this->getJson('/api/v1/vehicles?filter[availability]=sold', api($this->token))->json('data.0.attributes.availability'))->toBe('sold');

    Carbon::setTestNow('2026-10-18 09:00'); // more than 7 days after the sale
    $this->getJson('/api/v1/vehicles/'.$listing->id, api($this->token))->assertNotFound();
    expect($this->getJson('/api/v1/vehicles', api($this->token))->json('data'))->toBe([]);
});

it('filters, sorts and paginates', function () {
    asTenant($this->tenant, function () {
        publishedGolf();
        $cheap = publishedGolf();
        app(PublishListing::class)(app(SaveListing::class)($cheap->stockCycle, ['price_rp' => 990_000]));
    });

    $this->getJson('/api/v1/vehicles?sort=price&page[size]=1', api($this->token))
        ->assertOk()
        ->assertJsonPath('data.0.attributes.price.amount', '9900.00')
        ->assertJsonPath('meta.total', 2);

    expect($this->getJson('/api/v1/vehicles?filter[price_max]=10000', api($this->token))->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/vehicles?filter[make]=vw&filter[fuel]=diesel', api($this->token))->json('data'))->toHaveCount(2)
        ->and($this->getJson('/api/v1/vehicles?filter[make]=Audi', api($this->token))->json('data'))->toHaveCount(0);
});

it('refuses missing, revoked and under-privileged tokens and never shows another dealer', function () {
    asTenant($this->tenant, fn () => publishedGolf());
    $other = makeDealer(['slug' => 'other']);
    [, $otherToken] = asTenant($other, fn () => app(IssueApiToken::class)('Website', ['listings:read']));
    [$readOnly, $readOnlyPlain] = asTenant($this->tenant, fn () => app(IssueApiToken::class)('Read only', ['listings:read']));

    $this->getJson('/api/v1/vehicles')->assertUnauthorized();
    $this->getJson('/api/v1/vehicles', api('dsk_wrong'))->assertUnauthorized();
    expect($this->getJson('/api/v1/vehicles', api($otherToken))->assertOk()->json('data'))->toBe([]);
    $this->postJson('/api/v1/enquiries', ['name' => 'X', 'email' => 'x@example.ch', 'message' => 'Hi'], api($readOnlyPlain))->assertForbidden();

    asTenant($this->tenant, fn () => app(IssueApiToken::class)->revoke($readOnly));
    $this->getJson('/api/v1/vehicles', api($readOnlyPlain))->assertUnauthorized();
    $this->getJson('/api/v1/dealer', api($this->token))->assertOk()->assertJsonPath('data.attributes.email', 'info@aziri.example.ch');
});

it('receives an enquiry from the website, links the contact and tells the dealer', function () {
    Mail::fake();
    $listing = asTenant($this->tenant, fn () => publishedGolf());
    $known = asTenant($this->tenant, fn () => Party::factory()->create(['email' => 'anna.meier@example.ch']));

    $this->postJson('/api/v1/enquiries', [
        'vehicle_id' => $listing->id,
        'name' => 'Anna Meier',
        'email' => 'Anna.Meier@example.ch',
        'message' => "Ist der Golf noch da?\nProbefahrt am Samstag?",
        'visitor_ip' => '203.0.113.7',
    ], api($this->token))->assertCreated()->assertJsonPath('data.type', 'enquiries');

    $this->postJson('/api/v1/enquiries', ['name' => 'Luca Rossi', 'phone' => '076 111 22 33', 'message' => 'Avete Audi?', 'locale' => 'it'], api($this->token))->assertCreated();
    $this->postJson('/api/v1/enquiries', ['name' => 'Bot', 'email' => 'bot@example.ch', 'message' => 'spam', 'website' => 'http://spam'], api($this->token))->assertUnprocessable();
    $this->postJson('/api/v1/enquiries', ['name' => 'No contact', 'message' => 'Hi'], api($this->token))->assertUnprocessable();

    asTenant($this->tenant, function () use ($listing, $known) {
        $first = Enquiry::query()->where('name', 'Anna Meier')->sole();
        $second = Enquiry::query()->where('name', 'Luca Rossi')->sole();

        expect($first->party_id)->toBe($known->id) // found by e-mail
            ->and($first->stock_cycle_id)->toBe($listing->stock_cycle_id)
            ->and($first->ip)->toBe('203.0.113.7')
            ->and($second->party->last_name)->toBe('Rossi')
            ->and($second->party->locale)->toBe('it');
    });

    Mail::assertQueued(EnquiryReceivedMail::class, fn (EnquiryReceivedMail $mail): bool => $mail->hasTo('info@aziri.example.ch') && $mail->vehicle !== null);
});

it('signs webhooks, logs deliveries and switches off a failing endpoint', function () {
    $endpoint = asTenant($this->tenant, fn () => WebhookEndpoint::create(['url' => 'https://hooks.example.ch/dms', 'secret' => 'whsec_test', 'events' => ['vehicle.listed', 'vehicle.unlisted']]));
    $listing = asTenant($this->tenant, fn () => publishedGolf());

    Http::assertSent(function ($request): bool {
        [$t, $v1] = explode(',', $request->header('X-Dealer-Signature')[0]);

        return $request->url() === 'https://hooks.example.ch/dms'
            && $request->header('X-Dealer-Event')[0] === 'vehicle.listed'
            && $v1 === 'v1='.hash_hmac('sha256', substr($t, 2).'.'.$request->body(), 'whsec_test')
            && $request['data']['availability'] === 'available';
    });

    asTenant($this->tenant, function () use ($listing, $endpoint) {
        app(PublishListing::class)->withdraw($listing);

        expect($listing->stockCycle->refresh()->status)->toBe(StockCycleStatus::ReadyForSale)
            ->and($listing->refresh()->availability())->toBe(Availability::Hidden)
            ->and(WebhookDelivery::query()->where('status', 'delivered')->pluck('event')->all())->toEqualCanonicalizing(['vehicle.listed', 'vehicle.unlisted'])
            ->and($endpoint->refresh()->last_success_at)->not->toBeNull()
            ->and(Webhooks::signature('s', '{}', 1))->toBe('t=1,v1='.hash_hmac('sha256', '1.{}', 's'));

        // A failing receiver: failures are counted and the endpoint is switched off at the limit.
        Http::fake(['https://down.example.ch/*' => Http::response('down', 500)]);
        $endpoint->forceFill(['url' => 'https://down.example.ch/dms', 'consecutive_failures' => WebhookEndpoint::MAX_FAILURES - 1])->save();
        app(Webhooks::class)->dispatch('vehicle.listed', ['test' => true]);

        expect($endpoint->refresh()->is_active)->toBeFalse()
            ->and(WebhookDelivery::query()->where('response_code', 500)->where('status', 'pending')->count())->toBe(1);
    });
});

it('refuses to publish without photo or before the car is ready', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::InPreparation)->create(['list_price_rp' => 1_000_000]);

        expect(fn () => app(PublishListing::class)(app(SaveListing::class)($cycle)))
            ->toThrow(BusinessRuleException::class, 'ready for sale');
    });
});
