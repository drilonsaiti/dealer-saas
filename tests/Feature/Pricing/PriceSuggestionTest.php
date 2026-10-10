<?php

use App\Domain\Listings\Actions\PublishListing;
use App\Domain\Listings\Actions\SaveListing;
use App\Domain\Listings\Models\Listing;
use App\Domain\Parties\Models\Party;
use App\Domain\Pricing\Actions\SuggestPrice;
use App\Domain\Purchasing\Actions\RecordPurchase;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\VehicleData\Models\VehicleValuation;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Pages\Tenancy\EditCompanyProfile;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Filament\App\Widgets\LongestInStock;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Price suggestions by days in stock (Phase 4): steps on the planned price, capped by a known
 * market value, never below cost plus minimum margin; applied with one click.
 */

beforeEach(function () {
    app()->setLocale('en');
    Carbon::setTestNow('2026-10-10 09:00');
    $this->tenant = makeDealer(['slug' => 'aziri']);
    $this->admin = makeMember($this->tenant, Role::Administrator);
    $this->actingAs($this->admin);
});

afterEach(fn () => Carbon::setTestNow());

/** A Golf bought for 15'000, planned at 20'000, listed at 20'000, purchased :days days ago. */
function standingGolf(int $days, int $listRp = 2_000_000): StockCycle
{
    $cycle = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)->create(['planned_price_rp' => 2_000_000, 'list_price_rp' => $listRp]);
    app(RecordPurchase::class)($cycle, ['seller_party_id' => Party::factory()->create()->id, 'seller_kind' => 'private', 'contract_on' => now()->subDays($days)->toDateString(), 'price_rp' => 1_500_000]);
    $cycle->refresh()->forceFill(['status' => StockCycleStatus::ReadyForSale, 'purchased_on' => now()->subDays($days)->toDateString()])->save();

    return $cycle->refresh();
}

it('lowers the suggestion step by step with the days in stock', function () {
    asTenant($this->tenant, function () {
        expect(app(SuggestPrice::class)(standingGolf(30))->lowersPrice())->toBeFalse();

        $s45 = app(SuggestPrice::class)(standingGolf(50));
        expect($s45->suggestedRp)->toBe(1_940_000) // −3 % of 20'000
            ->and($s45->reasons[0])->toContain('50 days in stock')->toContain('3 %');

        expect(app(SuggestPrice::class)(standingGolf(95))->suggestedRp)->toBe(1_840_000) // −8 %
            ->and(app(SuggestPrice::class)(standingGolf(130))->suggestedRp)->toBe(1_760_000); // −12 %

        // Already reduced by hand below the step: nothing to suggest.
        expect(app(SuggestPrice::class)(standingGolf(50, 1_900_000))->lowersPrice())->toBeFalse();
    });
});

it('caps at the market value and never goes below cost plus minimum margin', function () {
    asTenant($this->tenant, function () {
        $cycle = standingGolf(10);
        VehicleValuation::create(['stock_cycle_id' => $cycle->id, 'provider' => 'autoidat', 'valued_on' => '2026-10-01', 'mileage' => 50_000, 'retail_rp' => 1_850_000]);

        $suggestion = app(SuggestPrice::class)($cycle);
        expect($suggestion->suggestedRp)->toBe(1_850_000)
            ->and($suggestion->reasons[0])->toContain('Market value');

        // Valuation far below cost: the floor wins (15'000 + 500).
        VehicleValuation::create(['stock_cycle_id' => $cycle->id, 'provider' => 'autoidat', 'valued_on' => '2026-10-05', 'mileage' => 50_000, 'retail_rp' => 1_200_000]);
        $floored = app(SuggestPrice::class)($cycle);
        expect($floored->suggestedRp)->toBe(1_550_000)
            ->and($floored->floorRp)->toBe(1_550_000)
            ->and(collect($floored->reasons)->last())->toContain('Not below cost');
    });
});

it('uses the dealer\'s own steps and minimum margin', function () {
    asTenant($this->tenant, function () {
        $this->tenant->forceFill(['settings' => ['pricing' => ['steps' => [['days' => '20', 'percent' => '10']], 'min_margin_rp' => 100_000]]])->save();

        expect(app(SuggestPrice::class)(standingGolf(25))->suggestedRp)->toBe(1_800_000);

        $this->tenant->forceFill(['settings' => ['pricing' => ['steps' => [['days' => 20, 'percent' => 40]], 'min_margin_rp' => 100_000]]])->save();
        expect(app(SuggestPrice::class)(standingGolf(25))->suggestedRp)->toBe(1_600_000); // floor 15'000 + 1'000
    });
});

it('applies the price to the file and the advert, from the vehicle file', function () {
    useAppPanel($this->tenant, $this->admin);
    $cycle = standingGolf(95);
    attachPhoto($cycle);
    app(PublishListing::class)(app(SaveListing::class)($cycle->refresh(), []));

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->assertSee(__('Price suggestion'))
        ->assertSee('CHF 18’400.00')
        ->callAction('applyPrice', data: ['price_rp' => '18400'])
        ->assertHasNoActionErrors();

    expect($cycle->refresh()->list_price_rp)->toBe(1_840_000)
        ->and(Listing::query()->sole()->price_rp)->toBe(1_840_000);

    Livewire::test(LongestInStock::class)->assertSuccessful();
    Livewire::test(EditCompanyProfile::class)->assertSee(__('Price suggestions'));
});
