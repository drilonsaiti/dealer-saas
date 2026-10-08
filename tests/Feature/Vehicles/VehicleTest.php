<?php

use App\Domain\Parties\Models\Party;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Actions\FindVehicleDuplicates;
use App\Domain\Vehicles\Actions\OpenStockCycle;
use App\Domain\Vehicles\Actions\RecordVehicle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Support\BusinessRuleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app()->setLocale('en'); // assertions below match the English messages
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->actingAs(makeMember($this->tenant, Role::Sales));
});

it('stores the Stammnummer normalised and keeps it unique per dealer', function () {
    asTenant($this->tenant, function () {
        $vehicle = Vehicle::factory()->create(['stammnummer' => '683.737.537']);

        expect($vehicle->stammnummer)->toBe('683737537')
            ->and($vehicle->formattedStammnummer())->toBe('683.737.537');

        expect(fn () => DB::transaction(fn () => Vehicle::factory()->create(['stammnummer' => '683 737 537'])))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

it('lets two dealers have the same car', function () {
    $other = makeTenant(['name' => 'Garage B', 'slug' => 'garage-b']);

    asTenant($this->tenant, fn () => Vehicle::factory()->create(['stammnummer' => '683737537']));
    asTenant($other, fn () => Vehicle::factory()->create(['stammnummer' => '683737537']));

    expect(asTenant($other, fn () => Vehicle::count()))->toBe(1);
});

it('accepts a vehicle without Stammnummer and adds it later on the same record', function () {
    asTenant($this->tenant, function () {
        $first = Vehicle::factory()->withoutStammnummer()->create();
        Vehicle::factory()->withoutStammnummer()->create(); // several unknown numbers are fine

        $first->update(['stammnummer' => '653461306']);

        expect(Vehicle::where('stammnummer', '653461306')->sole()->id)->toBe($first->id);
    });
});

it('warns about a duplicate VIN without blocking it', function () {
    asTenant($this->tenant, function () {
        $existing = Vehicle::factory()->create(['vin' => 'WBAUZ71010VN12345']);
        Vehicle::factory()->create(['vin' => 'wbauz71010vn12345']);

        $duplicates = app(FindVehicleDuplicates::class)->byVin('WBAUZ71010VN12345', ignoreId: $existing->id);

        expect($duplicates)->toHaveCount(1);
    });
});

it('records a new vehicle with an open file', function () {
    $cycle = asTenant($this->tenant, fn () => app(RecordVehicle::class)(
        ['stammnummer' => '683737537', 'make' => 'Toyota', 'model' => 'Corolla'],
        ['mileage_in' => 79310],
    ));

    expect($cycle->status)->toBe(StockCycleStatus::InReview)
        ->and($cycle->number)->toBeNull()
        ->and($cycle->vehicle->displayName())->toBe('Toyota Corolla')
        ->and($cycle->created_by)->toBe(auth()->id());

    expect(asTenant($this->tenant, fn () => $cycle->statusHistory()->sole()->to_status))->toBe('in_review');
});

it('records a purchased vehicle with a cycle number and file year', function () {
    $cycle = asTenant($this->tenant, fn () => app(RecordVehicle::class)(
        ['stammnummer' => '683737537', 'make' => 'Toyota'],
        purchase: [
            'seller_party_id' => Party::factory()->create()->id,
            'seller_kind' => 'private',
            'contract_on' => '2025-12-18',
            'price_rp' => 1_520_000,
        ],
    ));

    expect($cycle->status)->toBe(StockCycleStatus::Purchased)
        ->and($cycle->number)->toBe(now()->format('Y').'-0001')
        ->and($cycle->file_year)->toBe(2025)
        ->and($cycle->purchased_on->toDateString())->toBe('2025-12-18');
});

it('opens a new file on the same vehicle when the car comes back', function () {
    asTenant($this->tenant, function () {
        $first = StockCycle::factory()->delivered()->create();
        $vehicle = $first->vehicle;

        $again = app(RecordVehicle::class)(['stammnummer' => $vehicle->stammnummer, 'plate' => 'BE 12345']);

        expect($again->vehicle_id)->toBe($vehicle->id)
            ->and($again->id)->not->toBe($first->id)
            ->and(Vehicle::count())->toBe(1)
            ->and($vehicle->refresh()->plate)->toBe('BE 12345')
            ->and($vehicle->make)->not->toBeNull(); // fields left empty in the form are kept
    });
});

it('never has the same car in stock twice', function () {
    asTenant($this->tenant, function () {
        $open = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)->create();

        expect(fn () => app(OpenStockCycle::class)($open->vehicle))
            ->toThrow(BusinessRuleException::class);

        // Also enforced by the database, should application code ever skip the check.
        $second = new StockCycle(['vehicle_id' => $open->vehicle_id]);
        $second->forceFill(['status' => StockCycleStatus::InReview]);

        expect(fn () => DB::transaction(fn () => $second->save()))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});
