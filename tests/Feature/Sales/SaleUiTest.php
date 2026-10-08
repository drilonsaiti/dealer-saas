<?php

use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\Sales\Pages\ListSales;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Filament\App\Widgets\LongestInStock;
use App\Filament\App\Widgets\MarginPerMonthChart;
use App\Filament\App\Widgets\StockOverview;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->user = makeMember($this->tenant, Role::Sales);
});

it('reserves, sells with a trade-in and hands over from the vehicle file', function () {
    useAppPanel($this->tenant, $this->user);

    $cycle = StockCycle::factory()->status(StockCycleStatus::Listed)->create(['list_price_rp' => 2_190_000, 'mileage_in' => 50_000]);
    Purchase::factory()->create(['stock_cycle_id' => $cycle->id, 'price_rp' => 1_700_000]);
    $buyer = Party::factory()->create(['locale' => 'fr']);

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->assertActionVisible('reserve')
        ->callAction('reserve', [
            'buyer_party_id' => $buyer->id,
            'payment_type' => 'bank',
            'price_rp' => '21’900',
            'discount_rp' => '0',
            'deposit_rp' => '1000',
            'reserved_until' => now()->addDays(5)->toDateString(),
            'locale' => 'fr',
            'items' => [],
            'has_trade_in' => false,
        ])
        ->assertHasNoActionErrors()
        ->assertActionHidden('reserve')
        ->assertActionVisible('cancelSale');

    expect($cycle->refresh()->status)->toBe(StockCycleStatus::Reserved);

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('sell', [
            'buyer_party_id' => $buyer->id,
            'payment_type' => 'bank',
            'price_rp' => '21’900',
            'discount_rp' => '400',
            'deposit_rp' => '1000',
            'sale_on' => now()->toDateString(),
            'locale' => 'fr',
            'items' => [['kind' => 'warranty', 'description' => 'Garantie 12 mois', 'qty' => 1, 'unit_price_rp' => '490']],
            'has_trade_in' => true,
            'trade_in' => [
                'vehicle' => ['stammnummer' => '311.908.112', 'make' => 'VW', 'model' => 'Polo'],
                'mileage' => 112000,
                'value_rp' => '4500',
                'payoff_rp' => '0',
                'customer_payout_rp' => '0',
                'customer_topup_rp' => '0',
            ],
        ])
        ->assertHasNoActionErrors()
        ->assertActionVisible('handOver');

    $sale = Sale::sole();

    expect($sale->status)->toBe(SaleStatus::Contracted)
        ->and($sale->totalRp())->toBe(2_190_000 - 40_000 + 49_000)
        ->and($sale->balanceRp())->toBe(2_199_000 - 100_000 - 450_000)
        ->and($sale->tradeIn->purchaseCycle->status)->toBe(StockCycleStatus::Purchased);

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('handOver', ['on' => now()->toDateString(), 'mileage_out' => 50_120])
        ->assertHasNoActionErrors();

    expect($cycle->refresh()->status)->toBe(StockCycleStatus::Delivered)
        ->and($sale->refresh()->status)->toBe(SaleStatus::Delivered);
});

it('cancels a reservation with a reason', function () {
    useAppPanel($this->tenant, $this->user);

    $cycle = StockCycle::factory()->status(StockCycleStatus::Reserved)->create();
    Sale::factory()->status(SaleStatus::Reserved)->create(['stock_cycle_id' => $cycle->id, 'sale_on' => null]);

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('cancelSale', ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('cancelSale', ['reason' => 'Customer found another car'])
        ->assertHasNoActionErrors();

    expect($cycle->refresh()->status)->toBe(StockCycleStatus::ReadyForSale);
});

it('opens the dashboard', function () {
    $this->actingAs(makeMember($this->tenant, Role::ReadOnly))->get('/app/garage-a')->assertOk();
});

// No HTTP request in the same test: the request's end clears the tenant context, also for later Livewire calls.
it('shows the dashboard with stock figures and the sales overview', function () {
    $reader = makeMember($this->tenant, Role::ReadOnly); // reports, no 2FA requirement
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Listed)->create();
        Purchase::factory()->create(['stock_cycle_id' => $cycle->id]);
    });

    useAppPanel($this->tenant, $reader);
    Livewire::withoutLazyLoading()->test(StockOverview::class)->assertSee('Vehicles in stock')->assertSee('CHF 15’000.00');
    Livewire::withoutLazyLoading()->test(MarginPerMonthChart::class)->assertSee('Margin per month (CHF)');
    Livewire::withoutLazyLoading()->test(LongestInStock::class)->assertSee('Longest in stock');

    useAppPanel($this->tenant, $this->user);
    $sale = Sale::factory()->create();

    Livewire::test(ListSales::class)->assertCanSeeTableRecords([$sale]);
});
