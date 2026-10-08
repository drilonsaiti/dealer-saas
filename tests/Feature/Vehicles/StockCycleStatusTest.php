<?php

use App\Domain\Audit\Models\StatusHistory;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Actions\ArchiveDeliveredCycles;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Events\StockCycleStatusChanged;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    app()->setLocale('en'); // assertions below match the English messages
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->user = makeMember($this->tenant, Role::Sales);
    $this->actingAs($this->user);
});

function transition(StockCycle $cycle, StockCycleStatus $to, ?string $reason = null, array $data = []): StockCycle
{
    return app(TransitionStockCycle::class)($cycle, $to, $reason, $data);
}

it('walks the main flow and records every step', function () {
    Event::fake([StockCycleStatusChanged::class]);

    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->create(['mileage_in' => 79310]);

        transition($cycle, StockCycleStatus::Purchased, data: ['purchased_on' => now()->toDateString()]);
        transition($cycle, StockCycleStatus::Arrived);
        transition($cycle, StockCycleStatus::InPreparation);
        transition($cycle, StockCycleStatus::ReadyForSale);
        $cycle->update(['list_price_rp' => 2_190_000]);
        transition($cycle, StockCycleStatus::Listed);
        transition($cycle, StockCycleStatus::Sold);
        transition($cycle, StockCycleStatus::Delivered, data: ['mileage_out' => 79400]);

        $cycle->refresh();

        expect($cycle->status)->toBe(StockCycleStatus::Delivered)
            ->and($cycle->ready_on)->not->toBeNull()
            ->and($cycle->listed_on)->not->toBeNull()
            ->and($cycle->sold_on)->not->toBeNull()
            ->and($cycle->delivered_on)->not->toBeNull()
            ->and($cycle->mileage_out)->toBe(79400);

        $history = StatusHistory::where('subject_id', $cycle->id)->orderBy('created_at')->get();

        expect($history->pluck('to_status')->all())
            ->toBe(['purchased', 'arrived', 'in_preparation', 'ready_for_sale', 'listed', 'sold', 'delivered'])
            ->and($history->first()->user_id)->toBe($this->user->id);
    });

    Event::assertDispatchedTimes(StockCycleStatusChanged::class, 7);
});

it('refuses steps that are not in the flow', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->create();

        expect(fn () => transition($cycle, StockCycleStatus::Sold))
            ->toThrow(BusinessRuleException::class, 'cannot go from');

        expect($cycle->refresh()->status)->toBe(StockCycleStatus::InReview);
    });
});

it('needs a reason to go back', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Reserved)->create();

        expect(fn () => transition($cycle, StockCycleStatus::ReadyForSale))
            ->toThrow(BusinessRuleException::class, 'reason');

        transition($cycle, StockCycleStatus::ReadyForSale, 'Customer withdrew');

        expect(StatusHistory::where('subject_id', $cycle->id)->sole()->reason)->toBe('Customer withdrew');
    });
});

it('needs a reason to cancel a purchase', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->create();

        expect(fn () => transition($cycle, StockCycleStatus::Cancelled))->toThrow(BusinessRuleException::class);

        transition($cycle, StockCycleStatus::Cancelled, 'Seller sold elsewhere');

        expect($cycle->refresh()->status)->toBe(StockCycleStatus::Cancelled)
            ->and($cycle->isLocked())->toBeTrue();
    });
});

it('checks the guards', function () {
    asTenant($this->tenant, function () {
        $review = StockCycle::factory()->create();
        expect(fn () => transition($review, StockCycleStatus::Purchased))->toThrow(BusinessRuleException::class, 'purchase date');

        $ready = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)->create();
        expect(fn () => transition($ready, StockCycleStatus::Listed))->toThrow(BusinessRuleException::class, 'list price');

        $sold = StockCycle::factory()->status(StockCycleStatus::Sold)->create(['mileage_in' => 80000]);
        expect(fn () => transition($sold, StockCycleStatus::Delivered))->toThrow(BusinessRuleException::class, 'mileage')
            ->and(fn () => transition($sold, StockCycleStatus::Delivered, data: ['mileage_out' => 1000]))->toThrow(BusinessRuleException::class, 'lower');
    });
});

it('gives cycle numbers in order and keeps the purchase year as file year', function () {
    asTenant($this->tenant, function () {
        $a = transition(StockCycle::factory()->create(), StockCycleStatus::Purchased, data: ['purchased_on' => now()->toDateString()]);
        $b = transition(StockCycle::factory()->create(), StockCycleStatus::Purchased, data: ['purchased_on' => now()->subYear()->toDateString()]);

        $year = now()->format('Y');

        expect($a->number)->toBe("{$year}-0001")
            ->and($b->number)->toBe("{$year}-0002")
            ->and($b->file_year)->toBe((int) now()->subYear()->format('Y'));
    });
});

it('archives delivered files after the archive period', function () {
    asTenant($this->tenant, function () {
        $old = StockCycle::factory()->delivered(daysAgo: 31)->create();
        $recent = StockCycle::factory()->delivered(daysAgo: 5)->create();

        expect(fn () => transition($recent, StockCycleStatus::Archived))->toThrow(BusinessRuleException::class, '30 days');

        expect(app(ArchiveDeliveredCycles::class)())->toBe(1)
            ->and($old->refresh()->status)->toBe(StockCycleStatus::Archived)
            ->and($old->archived_on)->not->toBeNull()
            ->and($recent->refresh()->status)->toBe(StockCycleStatus::Delivered);
    });
});

it('runs the archive command per dealer', function () {
    asTenant($this->tenant, fn () => StockCycle::factory()->delivered(daysAgo: 45)->create());

    $this->artisan('vehicles:archive')->expectsOutputToContain('Garage A: 1')->assertSuccessful();

    expect(asTenant($this->tenant, fn () => StockCycle::sole()->status))->toBe(StockCycleStatus::Archived);
});

it('makes archived files read-only', function () {
    $cycle = asTenant($this->tenant, fn () => StockCycle::factory()->status(StockCycleStatus::Archived)->create());

    asTenant($this->tenant, function () use ($cycle) {
        expect($this->user->can('update', $cycle))->toBeFalse()
            ->and($this->user->can('transition', $cycle))->toBeFalse()
            ->and($this->user->can('delete', $cycle))->toBeFalse();
    });
});

it('counts days in stock from purchase to sale', function () {
    $cycle = new StockCycle;
    $cycle->forceFill(['purchased_on' => '2026-01-01', 'sold_on' => '2026-03-02']);

    expect($cycle->daysInStock())->toBe(60);

    $cycle->sold_on = null;
    expect($cycle->daysInStock(now()->setDate(2026, 1, 31)))->toBe(30);
});
