<?php

use App\Domain\Integrations\Actions\SaveIntegrationAccount;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Models\IntegrationLog;
use App\Domain\Integrations\Support\ListingSync;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\VehicleData\Actions\FetchVehicleData;
use App\Domain\VehicleData\Models\VehicleValuation;
use App\Domain\Vehicles\Enums\FuelType;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Filament\App\Resources\IntegrationAccounts\Pages\ManageIntegrationAccounts;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Vehicle data from a licensed provider (Auto-i-DAT, Phase 3): technical data and equipment
 * by type approval / VIN, valuation as history. The provider API is faked.
 */

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['slug' => 'aziri']);
    $this->admin = makeMember($this->tenant, Role::Administrator);
    $this->actingAs($this->admin);
    Http::preventStrayRequests();

    Http::fake([
        'api.auto-i-dat.ch/v1/vehicles/AID-1/valuation*' => Http::response(['retailPrice' => 18450, 'tradeInPrice' => 15200.5, 'valuationId' => 'V-77']),
        'api.auto-i-dat.ch/v1/vehicles*' => function (Request $request) {
            if ($request->header('Authorization')[0] !== 'Bearer key-1' || $request->header('X-Customer-Number')[0] !== 'C-9') {
                return Http::response(['message' => 'invalid key'], 401);
            }

            return Http::response(['items' => [
                ['id' => 'AID-1', 'make' => 'Skoda', 'model' => 'Octavia', 'version' => 'Combi 2.0 TDI 4x4', 'typeApproval' => '1SB123', 'bodyType' => 'estate', 'fuelType' => 'diesel', 'transmissionType' => 'automatic', 'driveType' => '4x4', 'powerKw' => 110, 'cubicCapacity' => 1968, 'doors' => 5, 'seats' => 5, 'curbWeight' => 1550, 'totalWeight' => 2100,
                    'standardEquipment' => ['ABS', 'Klimaanlage'], 'optionalEquipment' => [['name' => 'Anhängerkupplung'], ['name' => 'Panoramadach'], ['name' => 'Standheizung']], 'newPrice' => 48900],
                ['id' => 'AID-2', 'make' => 'Skoda', 'model' => 'Octavia', 'version' => 'Combi 2.0 TDI', 'powerKw' => 85],
            ]]);
        },
    ]);

    $this->cycle = asTenant($this->tenant, function () {
        app(SaveIntegrationAccount::class)(IntegrationAccount::AUTOIDAT, ['customer_number' => 'C-9', 'api_key' => 'key-1'], [], true);

        return StockCycle::factory()->for(Vehicle::factory()->state([
            'make' => 'Skoda', 'model' => 'Octavia', 'variant' => null, 'type_approval' => '1SB 123', 'fuel' => 'petrol', 'power_kw' => null,
            'first_registration_on' => '2021-03-15', 'equipment' => ['Winterräder'],
        ]))->create(['mileage_in' => 64_000]);
    });
});

it('finds the variants by type approval and takes over empty fields and the chosen options', function () {
    asTenant($this->tenant, function () {
        $fetch = app(FetchVehicleData::class);
        $variants = $fetch->lookup($this->cycle->vehicle);

        expect($variants)->toHaveCount(2)
            ->and($variants[0]->label())->toBe('Skoda Octavia Combi 2.0 TDI 4x4 · 110 kW')
            ->and($variants[0]->optionalEquipment)->toBe(['Anhängerkupplung', 'Panoramadach', 'Standheizung']);

        // Asked again while the dialog is open: no second call.
        $fetch->lookup($this->cycle->vehicle);
        Http::assertSentCount(1);

        $vehicle = $fetch->apply($this->cycle->vehicle, $variants[0], ['Anhängerkupplung', 'Not offered']);

        expect($vehicle->variant)->toBe('Combi 2.0 TDI 4x4')
            ->and($vehicle->power_kw)->toBe(110)
            ->and($vehicle->fuel)->toBe(FuelType::Petrol) // filled already: kept
            ->and($vehicle->type_approval)->toBe('1SB 123')
            ->and($vehicle->curb_weight_kg)->toBe(1550)
            ->and($vehicle->equipment)->toBe(['Winterräder', 'Anhängerkupplung']);

        $fetch->apply($vehicle, $variants[0], [], overwrite: true);
        expect($vehicle->refresh()->fuel)->toBe(FuelType::Diesel);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'typeApproval=1SB123'));
    });
});

it('keeps each valuation for the mileage it was made for', function () {
    $valuation = asTenant($this->tenant, fn () => app(FetchVehicleData::class)->value($this->cycle, 'AID-1'));

    expect($valuation->retail_rp)->toBe(1_845_000)
        ->and($valuation->trade_in_rp)->toBe(1_520_050)
        ->and($valuation->mileage)->toBe(64_000)
        ->and($valuation->reference)->toBe('V-77');

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'firstRegistration=2021-03') && str_contains($r->url(), 'mileage=64000'));
    expect(asTenant($this->tenant, fn () => IntegrationLog::query()->pluck('action')->all()))->toBe(['valuation']);
});

it('explains refused access and keeps it in the log', function () {
    asTenant($this->tenant, fn () => IntegrationAccount::query()->sole()->forceFill(['credentials' => ['customer_number' => 'C-9', 'api_key' => 'wrong']])->save());

    expect(fn () => asTenant($this->tenant, fn () => app(FetchVehicleData::class)->lookup($this->cycle->vehicle)))->toThrow(BusinessRuleException::class, 'refused access (401)');

    expect(asTenant($this->tenant, fn () => IntegrationLog::query()->sole()->status))->toBe('error');
});

it('does not treat the data provider as a portal', function () {
    expect(asTenant($this->tenant, fn () => app(ListingSync::class)->all()))->toBe(0);
});

it('offers "Fetch vehicle data" in the vehicle file and settings for both services', function () {
    useAppPanel($this->tenant, $this->admin);

    Livewire::test(ViewStockCycle::class, ['record' => $this->cycle->getRouteKey()])
        ->assertActionVisible('vehicleData')
        ->callAction('vehicleData', data: ['variant' => 'AID-1', 'options' => ['Panoramadach'], 'overwrite' => false, 'valuate' => true])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($this->cycle->vehicle->refresh()->power_kw)->toBe(110)
        ->and(VehicleValuation::query()->sole()->retail_rp)->toBe(1_845_000);

    Livewire::test(ViewStockCycle::class, ['record' => $this->cycle->getRouteKey()])
        ->assertSee(__('Market value'));

    Livewire::test(ManageIntegrationAccounts::class)
        ->callAction('connect', data: ['provider' => 'autoscout24', 'client_id' => 'cid', 'client_secret' => 's', 'seller_id' => 'S-1'])
        ->assertHasNoActionErrors()
        ->assertActionVisible('connect'); // WhatsApp can still be connected

    expect(IntegrationAccount::query()->pluck('provider')->sort()->values()->all())->toBe(['autoidat', 'autoscout24']);
});
