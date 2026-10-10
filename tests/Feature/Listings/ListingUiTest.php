<?php

use App\Domain\Api\Models\ApiToken;
use App\Domain\Api\Models\WebhookEndpoint;
use App\Domain\Listings\Actions\ReceiveEnquiry;
use App\Domain\Listings\Enums\EnquiryStatus;
use App\Domain\Listings\Enums\ListingStatus;
use App\Domain\Listings\Models\Enquiry;
use App\Domain\Listings\Models\Listing;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\ApiTokens\Pages\ManageApiTokens;
use App\Filament\App\Resources\Enquiries\Pages\ListEnquiries;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Filament\App\Resources\WebhookEndpoints\Pages\ManageWebhookEndpoints;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['slug' => 'aziri']);
    $this->admin = makeMember($this->tenant, Role::Administrator);
});

it('publishes and withdraws a listing from the vehicle file', function () {
    useAppPanel($this->tenant, $this->admin);
    $cycle = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)->create(['list_price_rp' => 1_590_000]);
    $photo = attachPhoto($cycle);

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->assertActionVisible('listing')
        ->callAction('listing', data: [
            'title' => ['de' => 'Skoda Octavia Combi 4x4', 'fr' => 'Skoda Octavia Combi 4x4'],
            'description' => ['de' => '<p>Top gepflegt</p>'],
            'highlights' => ['4x4', 'AHK'],
            'price_rp' => '15900',
            'show_price' => true,
            'photos' => [$photo->id],
            'cover' => $photo->id,
        ])
        ->assertHasNoActionErrors();

    $listing = Listing::query()->sole();

    expect($listing->status)->toBe(ListingStatus::Published)
        ->and($listing->highlights)->toBe(['4x4', 'AHK'])
        ->and($cycle->refresh()->status)->toBe(StockCycleStatus::Listed);

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('withdrawListing')
        ->assertHasNoActionErrors();

    expect($listing->refresh()->status)->toBe(ListingStatus::Withdrawn)
        ->and($cycle->refresh()->status)->toBe(StockCycleStatus::ReadyForSale);
});

it('creates an API token and a webhook, and handles enquiries', function () {
    useAppPanel($this->tenant, $this->admin);

    Livewire::test(ManageApiTokens::class)
        ->callAction('createToken', data: ['name' => 'Website', 'abilities' => ['listings:read', 'enquiries:write']])
        ->assertHasNoActionErrors()
        ->assertNotified();

    Livewire::test(ManageWebhookEndpoints::class)
        ->callAction('create', data: ['url' => 'https://example.ch/hook', 'events' => ['vehicle.sold'], 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(ApiToken::query()->sole()->abilities)->toBe(['listings:read', 'enquiries:write'])
        ->and(WebhookEndpoint::query()->sole()->secret)->toStartWith('whsec_');

    app(ReceiveEnquiry::class)(['name' => 'Anna Meier', 'email' => 'anna@example.ch', 'message' => 'Noch da?']);
    $enquiry = Enquiry::query()->sole();

    Livewire::test(ListEnquiries::class)
        ->assertCanSeeTableRecords([$enquiry])
        ->callTableAction('start', $enquiry)
        ->callTableAction('close', $enquiry->refresh());

    expect($enquiry->refresh()->status)->toBe(EnquiryStatus::Closed)
        ->and($enquiry->handled_by)->toBe($this->admin->id);
});

it('keeps API and webhook settings to administrators', function () {
    $sales = makeMember($this->tenant, Role::Sales);

    asTenant($this->tenant, fn () => expect($sales->can('viewAny', ApiToken::class))->toBeFalse()
        ->and($sales->can('viewAny', WebhookEndpoint::class))->toBeFalse()
        ->and($sales->can('update', new Enquiry))->toBeTrue());
});
