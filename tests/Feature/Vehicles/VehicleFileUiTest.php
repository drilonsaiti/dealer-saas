<?php

use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Filament\App\Resources\StockCycles\Pages\CreateStockCycle;
use App\Filament\App\Resources\StockCycles\Pages\EditStockCycle;
use App\Filament\App\Resources\StockCycles\Pages\ListStockCycles;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en'); // assertions below match the English messages
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->other = makeDealer(['name' => 'Garage B', 'slug' => 'garage-b']);
    $this->user = makeMember($this->tenant, Role::Sales);
});

it('lists only this dealer\'s stock', function () {
    $mine = asTenant($this->tenant, fn () => StockCycle::factory()->status(StockCycleStatus::ReadyForSale)
        ->for(Vehicle::factory()->state(['make' => 'Toyota', 'model' => 'Corolla']))->create());
    asTenant($this->other, fn () => StockCycle::factory()->status(StockCycleStatus::ReadyForSale)
        ->for(Vehicle::factory()->state(['make' => 'Ferrari', 'model' => 'Roma']))->create());

    $this->actingAs($this->user)
        ->get(StockCycleResource::getUrl('index', tenant: $this->tenant, panel: 'app'))
        ->assertOk()
        ->assertSee('Toyota Corolla')
        ->assertDontSee('Ferrari');

    $this->actingAs($this->user)
        ->get(StockCycleResource::getUrl('view', ['record' => $mine], tenant: $this->tenant, panel: 'app'))
        ->assertOk()
        ->assertSee($mine->number);
});

it('returns 404 for another dealer\'s vehicle file', function () {
    $foreign = asTenant($this->other, fn () => StockCycle::factory()->create());

    $this->actingAs($this->user)
        ->get("/app/garage-a/vehicles/{$foreign->id}")
        ->assertNotFound();
});

it('finds vehicles by Stammnummer with or without dots', function () {
    useAppPanel($this->tenant, $this->user);

    $car = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)
        ->for(Vehicle::factory()->state(['stammnummer' => '683737537']))->create();
    $otherCar = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)->create();

    Livewire::test(ListStockCycles::class)
        ->searchTable('683.737')
        ->assertCanSeeTableRecords([$car])
        ->assertCanNotSeeTableRecords([$otherCar]);
});

it('creates a purchased vehicle from the form', function () {
    useAppPanel($this->tenant, $this->user);

    Livewire::test(CreateStockCycle::class)
        ->fillForm([
            'vehicle.stammnummer' => '683.737.537',
            'vehicle.vin' => 'JTDKB20U403012345',
            'vehicle.make' => 'Toyota',
            'vehicle.model' => 'Corolla',
            'vehicle.vehicle_type' => 'passenger_car',
            'status' => 'purchased',
            'purchased_on' => now()->toDateString(),
            'mileage_in' => 79310,
            'list_price_rp' => "21'900",
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $cycle = StockCycle::with('vehicle')->sole();

    expect($cycle->status)->toBe(StockCycleStatus::Purchased)
        ->and($cycle->number)->not->toBeNull()
        ->and($cycle->list_price_rp)->toBe(2_190_000)
        ->and($cycle->vehicle->stammnummer)->toBe('683737537');
});

it('refuses a Stammnummer that is already in stock', function () {
    useAppPanel($this->tenant, $this->user);

    StockCycle::factory()->status(StockCycleStatus::Listed)
        ->for(Vehicle::factory()->state(['stammnummer' => '683737537']))->create();

    Livewire::test(CreateStockCycle::class)
        ->fillForm([
            'vehicle.stammnummer' => '683737537',
            'vehicle.make' => 'Toyota',
            'status' => 'in_review',
        ])
        ->call('create')
        ->assertHasFormErrors(['vehicle.stammnummer']);

    expect(StockCycle::count())->toBe(1);
});

it('changes the status from the vehicle file', function () {
    useAppPanel($this->tenant, $this->user);

    $cycle = StockCycle::factory()->status(StockCycleStatus::Arrived)->create();

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('status_ready_for_sale', ['on' => now()->toDateString()])
        ->assertHasNoActionErrors();

    expect($cycle->refresh()->status)->toBe(StockCycleStatus::ReadyForSale);
});

it('asks for a reason when going back', function () {
    useAppPanel($this->tenant, $this->user);

    $cycle = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)->create();

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('status_in_preparation', ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($cycle->refresh()->status)->toBe(StockCycleStatus::ReadyForSale);
});

it('edits vehicle data but never the status', function () {
    useAppPanel($this->tenant, $this->user);

    $cycle = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)->create();

    Livewire::test(EditStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->fillForm(['vehicle.plate' => 'be 123 456', 'list_price_rp' => '18’500.–'])
        ->call('save')
        ->assertHasNoFormErrors();

    $cycle->refresh();

    expect($cycle->vehicle->plate)->toBe('BE 123 456')
        ->and($cycle->list_price_rp)->toBe(1_850_000)
        ->and($cycle->status)->toBe(StockCycleStatus::ReadyForSale);
});

it('lets read-only users look but not change anything', function () {
    $reader = makeMember($this->tenant, Role::ReadOnly);
    $cycle = asTenant($this->tenant, fn () => StockCycle::factory()->status(StockCycleStatus::Arrived)->create());

    $this->actingAs($reader)->get('/app/garage-a/vehicles')->assertOk();
    $this->actingAs($reader)->get('/app/garage-a/vehicles/create')->assertForbidden();
    $this->actingAs($reader)->get("/app/garage-a/vehicles/{$cycle->id}/edit")->assertForbidden();

    useAppPanel($this->tenant, $reader);

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->assertActionHidden('status_ready_for_sale');
});
