<?php

use App\Domain\Calendar\Actions\IssueCalendarFeed;
use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Financing\Models\BuybackObligation;
use App\Domain\Financing\Models\Financing;
use App\Domain\Parties\Models\Party;
use App\Domain\Preparation\Models\RepairOrder;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyProduct;
use App\Filament\App\Pages\CalendarSubscription;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Personal calendar subscription (Phase 3): the dealer's important dates as an iCal feed behind
 * a secret link per user.
 */

beforeEach(function () {
    app()->setLocale('en');
    Carbon::setTestNow('2026-10-10 09:00');
    $this->tenant = makeDealer(['slug' => 'aziri', 'name' => 'Aziri Automobile']);
    $this->user = makeMember($this->tenant, Role::Sales, ['locale' => 'de']);
    $this->actingAs($this->user);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Unfolds the ICS lines (RFC 5545) for easy assertions.
 */
function unfoldIcs(string $ics): string
{
    return str_replace("\r\n ", '', $ics);
}

it('lists the important dates of the dealer in the personal calendar', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale(['reserved_until' => '2026-10-15']);
        $sale->forceFill(['planned_handover_on' => '2026-10-20'])->save();

        StockCycle::factory()->status(StockCycleStatus::ReadyForSale)
            ->for(Vehicle::factory()->state(['make' => 'Skoda', 'model' => 'Octavia', 'mfk_due_on' => '2026-11-03']))->create();
        $inPrep = StockCycle::factory()->status(StockCycleStatus::InPreparation)
            ->for(Vehicle::factory()->state(['make' => 'Fiat', 'model' => 'Panda']))->create(['prep_target_on' => '2026-10-12']);
        RepairOrder::create(['stock_cycle_id' => $inPrep->id, 'description' => 'Bremsen vorne', 'target_on' => '2026-10-11']);

        $product = WarrantyProduct::create(['name' => ['de' => 'Garantie'], 'duration_months' => 12]);
        Warranty::create(['stock_cycle_id' => $sale->stock_cycle_id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'duration_months' => 12, 'policy_number' => 'P-1'])
            ->forceFill(['status' => WarrantyStatus::Active, 'starts_on' => '2025-12-01', 'ends_on' => '2026-11-30'])->save();

        $financing = Financing::create(['sale_id' => $sale->id, 'partner_party_id' => Party::factory()->create(['company_name' => 'Leasingbank AG', 'kind' => 'company'])->id, 'applied_on' => '2026-10-01', 'cash_price_rp' => 2_690_000]);
        $financing->forceFill(['status' => FinancingStatus::DocumentsSent, 'payout_due_on' => '2026-10-21'])->save();
        BuybackObligation::create(['financing_id' => $financing->id, 'vehicle_id' => $sale->stockCycle->vehicle_id, 'amount_rp' => 1_000_000, 'due_on' => '2027-09-30', 'remind_on' => '2027-06-30']);

        // Outside the window or done: not shown.
        StockCycle::factory()->status(StockCycleStatus::ReadyForSale)->for(Vehicle::factory()->state(['mfk_due_on' => '2029-01-01']))->create();
    });

    [, $url] = asTenant($this->tenant, fn () => app(IssueCalendarFeed::class)($this->user));
    auth()->logout();

    $response = $this->get($url);
    $response->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    $ics = unfoldIcs($response->getContent());

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\nVERSION:2.0")
        ->toContain('X-WR-CALNAME:Aziri Automobile')
        ->toContain("DTSTART;VALUE=DATE:20261020\r\nDTEND;VALUE=DATE:20261021")
        ->toContain('SUMMARY:Übergabe: ')
        ->toContain('an Anna Muster')
        ->toContain('Reservation endet: ')->not->toContain('Reservation ends')
        ->toContain('MFK fällig: ')
        ->toContain('Verkaufsbereit bis: ')
        ->toContain('Reparatur fällig: ')
        ->toContain('Bremsen vorne')
        ->toContain('Garantie endet: ')
        ->toContain('Rückkauf-Erinnerung: ')
        ->toContain('Auszahlung fällig: Leasingbank AG')
        ->toContain('/app/aziri/vehicles/')
        ->not->toContain('20290101')
        ->toContain('Rückkauf fällig: ')
        ->and(substr_count($ics, 'BEGIN:VEVENT'))->toBe(9);

    foreach (explode("\r\n", $response->getContent()) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }
});

it('stops working when renewed, switched off or the user left the dealer', function () {
    [$feed, $url] = asTenant($this->tenant, fn () => app(IssueCalendarFeed::class)($this->user));
    $this->get($url)->assertOk();
    $this->get(str_replace('cal_', 'cal_x', $url))->assertNotFound();

    [, $newUrl] = asTenant($this->tenant, fn () => app(IssueCalendarFeed::class)($this->user));
    $this->get($url)->assertNotFound();
    $this->get($newUrl)->assertOk();

    TenantMembership::query()->withoutGlobalScopes()->where('user_id', $this->user->id)->update(['is_active' => false]);
    $this->user->forgetRoleCache();
    $this->get($newUrl)->assertNotFound();
});

it('creates, shows once and switches off the link on "My calendar"', function () {
    useAppPanel($this->tenant, $this->user);

    $page = Livewire::test(CalendarSubscription::class)
        ->assertSee(__('No calendar link yet.'))
        ->callAction('create')
        ->assertNotified();

    $url = $page->get('newUrl');
    expect($url)->toStartWith(url('/calendar/cal_'))->toEndWith('.ics');
    $page->assertSee($url);

    Livewire::test(CalendarSubscription::class)
        ->assertDontSee($url) // shown only once
        ->callAction('revoke');

    $this->get($url)->assertNotFound();
});
