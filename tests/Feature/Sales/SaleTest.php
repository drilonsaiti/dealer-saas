<?php

use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Actions\ConfirmCost;
use App\Domain\Purchasing\Actions\RecordCost;
use App\Domain\Purchasing\Enums\PurchaseType;
use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Reporting\CalculateMargin;
use App\Domain\Reporting\Margin;
use App\Domain\Reporting\StockReport;
use App\Domain\Sales\Actions\CancelSale;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Sales\Actions\HandOverVehicle;
use App\Domain\Sales\Actions\ReserveVehicle;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Support\BusinessRuleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->user = makeMember($this->tenant, Role::Sales);
    $this->actingAs($this->user);
});

function saleTerms(array $overrides = []): array
{
    return [
        'buyer_party_id' => Party::factory()->create()->id,
        'price_rp' => 2_190_000,
        'payment_type' => 'bank',
        ...$overrides,
    ];
}

it('reserves, contracts and hands over a car', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Listed)->create(['mileage_in' => 79_310]);

        $sale = app(ReserveVehicle::class)($cycle, saleTerms());

        expect($cycle->refresh()->status)->toBe(StockCycleStatus::Reserved)
            ->and($sale->status)->toBe(SaleStatus::Reserved)
            ->and($sale->reserved_until)->not->toBeNull();

        $contracted = app(ContractSale::class)($cycle, ['discount_rp' => 40_000, 'deposit_rp' => 200_000]);

        expect($contracted->id)->toBe($sale->id)
            ->and($contracted->status)->toBe(SaleStatus::Contracted)
            ->and($contracted->sale_on)->not->toBeNull()
            ->and($contracted->reserved_until)->toBeNull()
            ->and($cycle->refresh()->status)->toBe(StockCycleStatus::Sold)
            ->and($contracted->balanceRp())->toBe(2_190_000 - 40_000 - 200_000);

        app(HandOverVehicle::class)($contracted, 79_400);

        expect($cycle->refresh()->status)->toBe(StockCycleStatus::Delivered)
            ->and($cycle->mileage_out)->toBe(79_400)
            ->and($contracted->refresh()->status)->toBe(SaleStatus::Delivered)
            ->and($contracted->mileage_at_handover)->toBe(79_400);
    });
});

it('never sells or reserves the same car twice', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)->create();

        app(ReserveVehicle::class)($cycle, saleTerms());

        expect(fn () => app(ReserveVehicle::class)($cycle->refresh(), saleTerms()))
            ->toThrow(BusinessRuleException::class);

        // The database refuses a second active sale even without the actions.
        expect(fn () => DB::transaction(fn () => Sale::factory()->create(['stock_cycle_id' => $cycle->id])))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

it('puts the car back on sale when the reservation is cancelled', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Listed)->create();
        $sale = app(ReserveVehicle::class)($cycle, saleTerms());

        // Not with a plain status change: the reservation must be cancelled.
        expect(fn () => app(TransitionStockCycle::class)($cycle->refresh(), StockCycleStatus::ReadyForSale, 'customer withdrew'))
            ->toThrow(BusinessRuleException::class, 'Cancel the reservation');

        app(CancelSale::class)($sale, 'Customer withdrew');

        expect($sale->refresh()->status)->toBe(SaleStatus::Cancelled)
            ->and($sale->cancel_reason)->toBe('Customer withdrew')
            ->and($cycle->refresh()->status)->toBe(StockCycleStatus::ReadyForSale);

        // ...and the car can be reserved again.
        app(ReserveVehicle::class)($cycle, saleTerms());
        expect($cycle->refresh()->status)->toBe(StockCycleStatus::Reserved);
    });
});

/*
 * Acceptance test 6: a sale with trade-in creates the trade-in vehicle file.
 */
it('creates the trade-in vehicle file when the sale is contracted', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Listed)->create();
        $buyer = Party::factory()->create(['first_name' => 'Anna', 'last_name' => 'Meier']);

        $sale = app(ContractSale::class)($cycle, saleTerms([
            'buyer_party_id' => $buyer->id,
            'price_rp' => 2_690_000,
            'trade_in' => [
                'vehicle' => ['stammnummer' => '507.112.840', 'make' => 'VW', 'model' => 'Polo'],
                'mileage' => 98_000,
                'value_rp' => 500_000,
                'payoff_rp' => 150_000,
                'customer_payout_rp' => 0,
                'customer_topup_rp' => 0,
            ],
        ]));

        $tradeIn = $sale->tradeIn()->sole();
        $newCycle = $tradeIn->purchaseCycle;

        expect($tradeIn->isConfirmed())->toBeTrue()
            ->and($tradeIn->credited_rp)->toBe(350_000) // 5’000 value − 1’500 payoff
            ->and($sale->refresh()->balanceRp())->toBe(2_690_000 - 350_000)
            ->and($newCycle->status)->toBe(StockCycleStatus::Purchased)
            ->and($newCycle->number)->not->toBeNull()
            ->and($newCycle->mileage_in)->toBe(98_000)
            ->and($newCycle->vehicle->stammnummer)->toBe('507112840')
            ->and($newCycle->purchase->purchase_type)->toBe(PurchaseType::TradeIn)
            ->and($newCycle->purchase->seller_party_id)->toBe($buyer->id)
            ->and($newCycle->purchase->price_rp)->toBe(500_000)
            ->and($newCycle->tradeInSource->sale_id)->toBe($sale->id);
    });
});

it('finds a known trade-in car instead of creating it twice', function () {
    asTenant($this->tenant, function () {
        $old = StockCycle::factory()->delivered()->for(Vehicle::factory()->state(['stammnummer' => '507112840']))->create();
        $cycle = StockCycle::factory()->status(StockCycleStatus::Listed)->create();

        $sale = app(ContractSale::class)($cycle, saleTerms([
            'trade_in' => ['vehicle' => ['stammnummer' => '507112840'], 'value_rp' => 400_000],
        ]));

        expect($sale->tradeIn->purchaseCycle->vehicle_id)->toBe($old->vehicle_id)
            ->and(Vehicle::where('stammnummer', '507112840')->count())->toBe(1);
    });
});

it('cancels the trade-in file together with the sale', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Listed)->create();
        $sale = app(ContractSale::class)($cycle, saleTerms(['trade_in' => ['vehicle' => ['make' => 'VW'], 'value_rp' => 400_000]]));
        $tradeInCycle = $sale->tradeIn->purchaseCycle;

        app(CancelSale::class)($sale, 'Financing refused');

        expect($tradeInCycle->refresh()->status)->toBe(StockCycleStatus::Cancelled)
            ->and($cycle->refresh()->status)->toBe(StockCycleStatus::ReadyForSale);
    });
});

/*
 * Acceptance test 9: full cost and margin calculation.
 */
it('calculates the margin from purchase, costs, promises and sale', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Listed)->create(['list_price_rp' => 1_890_000]);
        Purchase::factory()->create(['stock_cycle_id' => $cycle->id, 'price_rp' => 1_520_000]);
        $category = fn (string $key) => CostCategory::where('key', $key)->value('id');

        $transport = app(RecordCost::class)(['stock_cycle_id' => $cycle->id, 'category_id' => $category('transport'), 'incurred_on' => now()->toDateString(), 'gross_rp' => 35_000]);
        app(ConfirmCost::class)($transport);
        app(RecordCost::class)(['stock_cycle_id' => $cycle->id, 'category_id' => $category('preparation'), 'incurred_on' => now()->toDateString(), 'gross_rp' => 28_000]);
        Commitment::factory()->create(['stock_cycle_id' => $cycle->id, 'estimated_cost_rp' => 64_000]);

        // Before the sale: expected margin on the list price, provisional.
        $before = app(CalculateMargin::class)($cycle);
        expect($before->revenueBasis)->toBe(Margin::BASIS_LIST_PRICE)
            ->and($before->landedCostRp())->toBe(1_520_000 + 35_000 + 28_000 + 64_000)
            ->and($before->marginRp())->toBe(1_890_000 - 1_647_000)
            ->and($before->isProvisional)->toBeTrue();

        app(ContractSale::class)($cycle, saleTerms([
            'price_rp' => 1_890_000,
            'discount_rp' => 40_000,
            'items' => [['kind' => 'warranty', 'description' => 'Garantie 12 Monate', 'qty' => 1, 'unit_price_rp' => 49_000]],
        ]));

        $after = app(CalculateMargin::class)($cycle->refresh());
        expect($after->revenueBasis)->toBe(Margin::BASIS_SALE)
            ->and($after->revenueRp)->toBe(1_890_000 - 40_000 + 49_000)
            ->and($after->marginRp())->toBe(1_899_000 - 1_647_000)
            ->and($after->marginPercent())->toBe(13.3)
            ->and($after->isProvisional)->toBeTrue(); // a draft cost and an open promise remain
    });
});

it('is confirmed once every cost is confirmed and promises are replaced by real costs', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Listed)->create();
        Purchase::factory()->create(['stock_cycle_id' => $cycle->id, 'price_rp' => 1_000_000]);
        $promise = Commitment::factory()->create(['stock_cycle_id' => $cycle->id, 'estimated_cost_rp' => 50_000]);
        $cost = app(RecordCost::class)(['stock_cycle_id' => $cycle->id, 'category_id' => CostCategory::where('key', 'tyres')->value('id'), 'incurred_on' => now()->toDateString(), 'gross_rp' => 61_000, 'commitment_id' => $promise->id]);
        app(ConfirmCost::class)($cost);
        app(ContractSale::class)($cycle, saleTerms(['price_rp' => 1_200_000]));

        $margin = app(CalculateMargin::class)($cycle->refresh());

        expect($margin->isProvisional)->toBeFalse()
            ->and($margin->openPromisesRp)->toBe(0)
            ->and($margin->marginRp())->toBe(1_200_000 - 1_000_000 - 61_000);
    });
});

it('reports stock, stock value and sales per month', function () {
    asTenant($this->tenant, function () {
        $old = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)->create(['purchased_on' => now()->subDays(95)->toDateString()]);
        Purchase::factory()->create(['stock_cycle_id' => $old->id, 'price_rp' => 1_000_000]);
        $new = StockCycle::factory()->status(StockCycleStatus::InPreparation)->create(['purchased_on' => now()->subDays(10)->toDateString()]);
        Purchase::factory()->create(['stock_cycle_id' => $new->id, 'price_rp' => 500_000]);
        StockCycle::factory()->create(); // in review: not stock

        $sold = StockCycle::factory()->status(StockCycleStatus::Listed)->create();
        Purchase::factory()->create(['stock_cycle_id' => $sold->id, 'price_rp' => 800_000]);
        app(ContractSale::class)($sold, saleTerms(['price_rp' => 1_000_000]));

        $stock = app(StockReport::class)->stock();

        expect($stock['count'])->toBe(2)
            ->and($stock['value_rp'])->toBe(1_500_000)
            ->and($stock['over_90'])->toBe(1)
            ->and($stock['in_preparation'])->toBe(1);

        $months = app(StockReport::class)->salesPerMonth(3);
        $current = end($months);

        expect($months)->toHaveCount(3)
            ->and($current['count'])->toBe(1)
            ->and($current['margin_rp'])->toBe(200_000);
    });
});
