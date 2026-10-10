<?php

use App\Domain\Integrations\Actions\ImportPortalStock;
use App\Domain\Integrations\Actions\SaveIntegrationAccount;
use App\Domain\Integrations\Actions\TestIntegration;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Models\IntegrationLog;
use App\Domain\Listings\Actions\PublishListing;
use App\Domain\Listings\Actions\SaveListing;
use App\Domain\Listings\Enums\ListingStatus;
use App\Domain\Listings\Enums\PublicationStatus;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Models\ListingPublication;
use App\Domain\Parties\Models\Party;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Sales\Actions\ReserveVehicle;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Acceptance test 11 (portal part): a published car goes to AutoScout24 with the dealer's own
 * credentials, follows edits, reserved and sold, and failures are visible and retried.
 * The AutoScout24 API is faked (its documentation is only available to customers).
 */

const AS24 = 'https://api.autoscout24.ch';

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['slug' => 'aziri']);
    $this->actingAs(makeMember($this->tenant, Role::Administrator));
    $this->calls = [];
});

/**
 * Fakes the portal: token, create (id 9001), update, delete, stock list. Records every call.
 *
 * @param  array<string, mixed>  $overrides  method+path => response
 */
function fakeAutoScout24(object $test, array $overrides = []): void
{
    // Http::fake stacks (the first registered answer wins), so later calls only swap the overrides.
    $first = ! isset($test->as24Overrides);
    $test->as24Overrides = $overrides;

    if (! $first) {
        return;
    }

    Http::fake(function (Request $request) use ($test) {
        $overrides = $test->as24Overrides;
        $path = parse_url($request->url(), PHP_URL_PATH);
        $key = $request->method().' '.$path;
        $test->calls[] = ['key' => $key, 'body' => $request->data()];

        if (array_key_exists($key, $overrides)) {
            $response = $overrides[$key];

            return is_callable($response) ? $response($request) : $response;
        }

        return match (true) {
            $key === 'POST /oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
            $key === 'POST /dms/v1/sellers/S-77/listings' => Http::response(['id' => '9001', 'url' => 'https://www.autoscout24.ch/de/d/9001'], 201),
            str_starts_with($key, 'PUT /dms/v1/sellers/S-77/listings/') => Http::response(['id' => basename($path)]),
            str_starts_with($key, 'DELETE /dms/v1/sellers/S-77/listings/') => Http::response(null, 204),
            $key === 'GET /dms/v1/sellers/S-77/listings' => Http::response(['items' => [], 'totalPages' => 1]),
            default => Http::response(['message' => 'not faked: '.$key], 500),
        };
    });
}

function as24Account(bool $active = true, array $settings = []): IntegrationAccount
{
    return app(SaveIntegrationAccount::class)(IntegrationAccount::AUTOSCOUT24, ['client_id' => 'cid', 'client_secret' => 'secret', 'seller_id' => 'S-77'], $settings, $active);
}

function as24Golf(): Listing
{
    $cycle = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)
        ->for(Vehicle::factory()->state(['make' => 'VW', 'model' => 'Golf', 'variant' => '2.0 TDI', 'fuel' => 'diesel', 'transmission' => 'automatic', 'body_type' => 'estate', 'power_kw' => 110, 'first_registration_on' => '2021-04-12', 'vin' => 'WVWZZZAUZMW000001']))
        ->create(['mileage_in' => 48_000, 'list_price_rp' => 2_190_000]);
    attachPhoto($cycle);

    return app(PublishListing::class)(app(SaveListing::class)($cycle, ['title' => ['de' => 'VW Golf Variant', 'fr' => 'VW Golf break']]));
}

function callsTo(object $test, string $prefix): array
{
    return array_values(array_filter($test->calls, fn (array $c): bool => str_starts_with($c['key'], $prefix)));
}

it('publishes a car on AutoScout24 with the dealer credentials, follows edits and skips unchanged syncs (acceptance test 11)', function () {
    fakeAutoScout24($this);

    $listing = asTenant($this->tenant, function () {
        as24Account();

        return as24Golf();
    });

    $created = callsTo($this, 'POST /dms/v1/sellers/S-77/listings');
    expect($created)->toHaveCount(1)
        ->and($created[0]['body'])->toMatchArray([
            'make' => 'VW', 'model' => 'Golf', 'version' => '2.0 TDI', 'price' => '21900.00', 'currency' => 'CHF',
            'mileage' => 48_000, 'fuelType' => 'diesel', 'transmissionType' => 'automatic', 'bodyType' => 'estate',
            'firstRegistrationDate' => '2021-04', 'powerKw' => 110, 'reserved' => false,
        ])
        ->and($created[0]['body']['title'])->toMatchArray(['de' => 'VW Golf Variant', 'fr' => 'VW Golf break'])
        ->and($created[0]['body'])->not->toHaveKey('vin') // only when the dealer allows it
        ->and($created[0]['body']['images'][0]['url'])->toContain('/api/v1/photos/')
        ->and(callsTo($this, 'POST /oauth/token')[0]['body'])->toMatchArray(['grant_type' => 'client_credentials', 'client_id' => 'cid', 'client_secret' => 'secret']);

    $publication = asTenant($this->tenant, fn () => ListingPublication::query()->where('channel', 'autoscout24')->sole());
    expect($publication->status)->toBe(PublicationStatus::Published)
        ->and($publication->external_id)->toBe('9001')
        ->and($publication->external_url)->toBe('https://www.autoscout24.ch/de/d/9001');

    // Price change → update of the same entry; the token is reused.
    asTenant($this->tenant, fn () => app(SaveListing::class)($listing->stockCycle, ['price_rp' => 2_090_000]));
    $updates = callsTo($this, 'PUT /dms/v1/sellers/S-77/listings/9001');
    expect($updates)->toHaveCount(1)
        ->and($updates[0]['body']['price'])->toBe('20900.00')
        ->and(callsTo($this, 'POST /oauth/token'))->toHaveCount(1);

    // Nothing changed → no call at all.
    asTenant($this->tenant, fn () => app(PublishListing::class)($listing->refresh()));
    expect(callsTo($this, 'PUT '))->toHaveCount(1);

    $logs = asTenant($this->tenant, fn () => IntegrationLog::query()->orderBy('action')->pluck('action')->all());
    expect($logs)->toBe(['publish', 'update']);
});

it('marks the car reserved, removes it when sold or withdrawn, and creates it again when it went missing', function () {
    fakeAutoScout24($this);

    $listing = asTenant($this->tenant, function () {
        as24Account();

        return as24Golf();
    });

    asTenant($this->tenant, fn () => app(ReserveVehicle::class)($listing->stockCycle->refresh(), ['buyer_party_id' => Party::factory()->create()->id, 'price_rp' => 2_150_000]));
    expect(callsTo($this, 'PUT ')[0]['body']['reserved'])->toBeTrue();

    asTenant($this->tenant, fn () => app(ContractSale::class)($listing->stockCycle->refresh(), []));
    expect(callsTo($this, 'DELETE /dms/v1/sellers/S-77/listings/9001'))->toHaveCount(1);

    $publication = asTenant($this->tenant, fn () => ListingPublication::query()->where('channel', 'autoscout24')->sole());
    expect($publication->status)->toBe(PublicationStatus::Removed)->and($publication->external_id)->toBeNull();
});

it('takes a withdrawn listing off the portal, and re-creates an entry the portal no longer knows', function () {
    fakeAutoScout24($this, ['PUT /dms/v1/sellers/S-77/listings/9001' => Http::response(['message' => 'gone'], 404)]);

    $listing = asTenant($this->tenant, function () {
        as24Account();

        return as24Golf();
    });

    // Deleted on the portal by hand: the update gets 404 and the car is created again.
    asTenant($this->tenant, fn () => app(SaveListing::class)($listing->stockCycle, ['price_rp' => 1_990_000]));
    expect(callsTo($this, 'POST /dms/v1/sellers/S-77/listings'))->toHaveCount(2);

    asTenant($this->tenant, fn () => app(PublishListing::class)->withdraw($listing->refresh()));
    expect(callsTo($this, 'DELETE /dms/v1/sellers/S-77/listings/9001'))->toHaveCount(1)
        ->and(asTenant($this->tenant, fn () => $listing->refresh()->status))->toBe(ListingStatus::Withdrawn);
});

it('logs refused vehicles and portal outages on the vehicle file, and the nightly sync sends them again', function () {
    fakeAutoScout24($this, ['POST /dms/v1/sellers/S-77/listings' => Http::response(['message' => 'Validation failed', 'errors' => [['field' => 'mileage', 'message' => 'must not be empty']]], 422)]);

    $listing = asTenant($this->tenant, function () {
        as24Account();

        return as24Golf();
    });

    [$publication, $log, $account] = asTenant($this->tenant, fn () => [
        ListingPublication::query()->where('channel', 'autoscout24')->sole(),
        IntegrationLog::query()->sole(),
        IntegrationAccount::query()->sole(),
    ]);

    expect($publication->status)->toBe(PublicationStatus::Failed)
        ->and($publication->last_error)->toContain('422')->toContain('mileage: must not be empty')
        ->and($log->status)->toBe('error')
        ->and($log->response_code)->toBe(422)
        ->and($account->last_error)->toContain('mileage');

    // The portal accepts it now: the nightly catch-up sends it.
    fakeAutoScout24($this);
    $this->artisan('listings:sync')->assertSuccessful();

    expect(asTenant($this->tenant, fn () => $publication->refresh()->status))->toBe(PublicationStatus::Published);
});

it('retries a portal outage with a pause instead of giving up', function () {
    fakeAutoScout24($this, ['POST /dms/v1/sellers/S-77/listings' => Http::response('Bad gateway', 502)]);
    config(['queue.default' => 'database']);

    asTenant($this->tenant, function () {
        as24Account();
        as24Golf();
    });

    // Queued after the commit; let the worker run what is due.
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--queue' => 'default'])->assertSuccessful();

    $job = DB::table('jobs')->where('payload', 'like', '%SyncListingPublication%')->first();
    expect($job)->not->toBeNull() // released again with a pause
        ->and($job->available_at)->toBeGreaterThan(now()->getTimestamp() + 30)
        ->and(asTenant($this->tenant, fn () => ListingPublication::query()->where('channel', 'autoscout24')->sole()->last_error))->toContain('502');
})->skip(fn () => ! Schema::hasTable('jobs'), 'needs the jobs table');

it('tests the connection and keeps secrets when the form leaves them empty', function () {
    fakeAutoScout24($this, ['POST /oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

    $account = asTenant($this->tenant, fn () => as24Account(active: false));
    $error = asTenant($this->tenant, fn () => app(TestIntegration::class)($account));

    expect($error)->toContain('refused the login (401)')
        ->and(asTenant($this->tenant, fn () => $account->refresh()->status))->toBe('error');

    fakeAutoScout24($this);
    $account = asTenant($this->tenant, fn () => app(SaveIntegrationAccount::class)('autoscout24', ['client_id' => 'cid', 'client_secret' => '', 'seller_id' => 'S-77']));

    expect($account->credential('client_secret'))->toBe('secret')
        ->and(asTenant($this->tenant, fn () => app(TestIntegration::class)($account)))->toBeNull()
        ->and(asTenant($this->tenant, fn () => $account->refresh()->status))->toBe('ok')
        ->and(asTenant($this->tenant, fn () => DB::table('integration_accounts')->value('credentials')))->toBeString()->not->toContain('secret'); // encrypted at rest
});

it('imports the stock from the portal once, linking a known VIN instead of creating it twice', function () {
    $known = asTenant($this->tenant, fn () => Vehicle::factory()->create(['vin' => 'WAUZZZ8V0KA000002', 'make' => 'Audi', 'model' => 'A3']));

    fakeAutoScout24($this, [
        'GET /dms/v1/sellers/S-77/listings' => Http::response(['items' => [
            ['id' => 'A-1', 'make' => 'Skoda', 'model' => 'Octavia', 'version' => 'Combi 4x4', 'vin' => 'TMBZZZNE0L0000003', 'firstRegistrationDate' => '2020-05', 'mileage' => 61000, 'price' => 18900, 'fuelType' => 'diesel', 'transmissionType' => 'manual', 'description' => ['de' => '<p>Gepflegt</p><script>x</script>'], 'images' => [['url' => 'https://img.autoscout24.ch/a1.jpg']], 'url' => 'https://www.autoscout24.ch/de/d/A-1'],
            ['id' => 'A-2', 'make' => 'Audi', 'model' => 'A3', 'vin' => 'WAUZZZ8V0KA000002', 'price' => ['amount' => 24500.5]],
            ['id' => 'A-3', 'make' => 'Fiat', 'model' => 'Panda'], // no price
        ], 'totalPages' => 1]),
        'GET /a1.jpg' => Http::response(base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $summary = asTenant($this->tenant, fn () => app(ImportPortalStock::class)(as24Account(active: false)));

    expect($summary)->toMatchArray(['created' => 1, 'linked' => 1, 'skipped' => 1, 'photos' => 1])
        ->and($summary['errors'][0])->toContain('Fiat Panda (A-3)');

    asTenant($this->tenant, function () use ($known) {
        $skoda = Vehicle::query()->where('vin', 'TMBZZZNE0L0000003')->sole();
        $cycle = $skoda->stockCycles()->sole();
        $listing = Listing::query()->where('stock_cycle_id', $cycle->id)->sole();

        expect($cycle->status)->toBe(StockCycleStatus::InReview)
            ->and($cycle->list_price_rp)->toBe(1_890_000)
            ->and($cycle->mileage_in)->toBe(61_000)
            ->and($skoda->first_registration_on->format('Y-m-d'))->toBe('2020-05-01')
            ->and($listing->status)->toBe(ListingStatus::Draft)
            ->and($listing->getTranslation('description', 'de'))->toBe('<p>Gepflegt</p>x')
            ->and($listing->photo_document_ids)->toHaveCount(1)
            ->and($listing->publications()->sole()->external_id)->toBe('A-1')
            ->and(Vehicle::query()->where('vin', 'WAUZZZ8V0KA000002')->count())->toBe(1)
            ->and($known->stockCycles()->sole()->list_price_rp)->toBe(2_450_050);
    });

    // Second run: everything known is skipped.
    $again = asTenant($this->tenant, fn () => app(ImportPortalStock::class)(IntegrationAccount::query()->sole(), withPhotos: false));
    expect($again)->toMatchArray(['created' => 0, 'linked' => 0, 'skipped' => 3]);
});

it('does nothing for dealers without an active portal account', function () {
    fakeAutoScout24($this);

    asTenant($this->tenant, function () {
        as24Account(active: false);
        as24Golf();
    });

    expect($this->calls)->toBe([]);
});
