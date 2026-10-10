<?php

use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Models\IntegrationLog;
use App\Domain\Listings\Actions\PublishListing;
use App\Domain\Listings\Actions\SaveListing;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\IntegrationAccounts\Pages\ManageIntegrationAccounts;
use App\Filament\App\Resources\IntegrationAccounts\Pages\ViewIntegrationAccount;
use App\Filament\App\Resources\IntegrationAccounts\RelationManagers\LogsRelationManager;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['slug' => 'aziri']);
    $this->admin = makeMember($this->tenant, Role::Administrator);

    Http::preventStrayRequests();
    Http::fake([
        'api.autoscout24.ch/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'api.autoscout24.ch/dms/v1/sellers/S-1/listings*' => Http::sequence()
            ->push(['items' => [], 'totalPages' => 1]) // connection test
            ->push(['id' => '777', 'url' => 'https://www.autoscout24.ch/de/d/777'], 201),
    ]);
});

it('connects AutoScout24, tests it, shows the log and the portal status on the vehicle file', function () {
    useAppPanel($this->tenant, $this->admin);

    Livewire::test(ManageIntegrationAccounts::class)
        ->callAction('connect', data: ['provider' => 'autoscout24', 'client_id' => 'cid', 'client_secret' => 'Sup3rS3cretValue', 'seller_id' => 'S-1', 'is_active' => true])
        ->assertHasNoActionErrors();

    $account = IntegrationAccount::query()->sole();

    Livewire::test(ManageIntegrationAccounts::class)
        ->assertCanSeeTableRecords([$account])
        ->assertActionVisible('connect'); // Auto-i-DAT can still be connected; AutoScout24 only once

    Livewire::test(ViewIntegrationAccount::class, ['record' => $account->getRouteKey()])
        ->assertSee('S-1')
        ->assertDontSee('Sup3rS3cretValue')
        ->callAction('test');

    expect($account->refresh()->last_error)->toBeNull()->and($account->status)->toBe('ok');

    $cycle = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)->create(['list_price_rp' => 1_500_000, 'mileage_in' => 10_000]);
    attachPhoto($cycle);
    app(PublishListing::class)(app(SaveListing::class)($cycle, []));

    Livewire::test(LogsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewIntegrationAccount::class])
        ->assertCanSeeTableRecords(IntegrationLog::query()->get())
        ->assertSee(__('Test connection'));

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->assertSee('AutoScout24: '.__('Online'));
});

it('keeps integrations to administrators', function () {
    $sales = makeMember($this->tenant, Role::Sales);

    asTenant($this->tenant, fn () => expect($sales->can('viewAny', IntegrationAccount::class))->toBeFalse()
        ->and($this->admin->can('viewAny', IntegrationAccount::class))->toBeTrue());
});
