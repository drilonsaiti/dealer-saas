<?php

namespace App\Domain\Calendar\Support;

use App\Domain\Financing\Enums\BuybackStatus;
use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Financing\Models\BuybackObligation;
use App\Domain\Financing\Models\Financing;
use App\Domain\Preparation\Enums\RepairOrderStatus;
use App\Domain\Preparation\Models\RepairOrder;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use App\Filament\App\Resources\BuybackObligations\BuybackObligationResource;
use App\Filament\App\Resources\Financings\FinancingResource;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Filament\App\Resources\Warranties\WarrantyResource;
use App\Models\User;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The dates a dealer must not miss, as all-day events for the personal calendar: planned
 * handovers, reservations ending, MFK due, preparation and repair targets, warranties ending,
 * buy-back reminders and dates, leasing payouts due. From 30 days back to 13 months ahead.
 */
class CalendarEvents
{
    private Carbon $from;

    private Carbon $until;

    public function __construct(private readonly Tenant $tenant, private readonly string $locale = 'de')
    {
        $this->from = Carbon::today()->subDays(30);
        $this->until = Carbon::today()->addMonths(13);
    }

    /**
     * @return list<CalendarEvent>
     */
    public function for(User $user): array
    {
        if (! $user->hasPermission(Permission::VehiclesView)) {
            return [];
        }

        $events = [
            ...$this->handovers(),
            ...$this->reservations(),
            ...$this->inspections(),
            ...$this->preparation(),
            ...$this->repairs(),
            ...$this->warranties(),
            ...$this->buybacks(),
            ...$this->payouts(),
        ];

        usort($events, fn (CalendarEvent $a, CalendarEvent $b): int => [$a->date, $a->summary] <=> [$b->date, $b->summary]);

        return $events;
    }

    /**
     * @return list<CalendarEvent>
     */
    private function handovers(): array
    {
        return Sale::query()->with(['stockCycle.vehicle', 'buyer'])
            ->whereIn('status', [SaleStatus::Reserved->value, SaleStatus::Contracted->value, SaleStatus::Invoiced->value])
            ->tap(fn (Builder $q) => $this->within($q, 'planned_handover_on'))
            ->get()
            ->map(fn (Sale $sale): CalendarEvent => new CalendarEvent(
                "handover-{$sale->id}@dealer-saas",
                $sale->planned_handover_on,
                $this->t('Handover: :vehicle to :buyer', ['vehicle' => $sale->stockCycle->title(), 'buyer' => $sale->buyer->displayName()]),
                $this->t('Planned handover. Check the handover checklist in the vehicle file.'),
                $this->cycleUrl($sale->stockCycle),
                $this->t('Handover'),
            ))->values()->all();
    }

    /**
     * @return list<CalendarEvent>
     */
    private function reservations(): array
    {
        return Sale::query()->with(['stockCycle.vehicle', 'buyer'])
            ->where('status', SaleStatus::Reserved->value)
            ->tap(fn (Builder $q) => $this->within($q, 'reserved_until'))
            ->get()
            ->map(fn (Sale $sale): CalendarEvent => new CalendarEvent(
                "reservation-{$sale->id}@dealer-saas",
                $sale->reserved_until,
                $this->t('Reservation ends: :vehicle (:buyer)', ['vehicle' => $sale->stockCycle->title(), 'buyer' => $sale->buyer->displayName()]),
                null,
                $this->cycleUrl($sale->stockCycle),
                $this->t('Reservation'),
            ))->values()->all();
    }

    /**
     * @return list<CalendarEvent>
     */
    private function inspections(): array
    {
        return StockCycle::query()->with('vehicle')
            ->whereIn('status', StockCycleStatus::inStockValues())
            ->whereHas('vehicle', fn (Builder $q) => $this->within($q, 'mfk_due_on'))
            ->get()
            ->map(fn (StockCycle $cycle): CalendarEvent => new CalendarEvent(
                "mfk-{$cycle->vehicle_id}-{$cycle->vehicle->mfk_due_on?->format('Ymd')}@dealer-saas",
                $cycle->vehicle->mfk_due_on ?? Carbon::today(),
                $this->t('MFK due: :vehicle', ['vehicle' => $cycle->title()]),
                null,
                $this->cycleUrl($cycle),
                $this->t('MFK'),
            ))->values()->all();
    }

    /**
     * @return list<CalendarEvent>
     */
    private function preparation(): array
    {
        return StockCycle::query()->with('vehicle')
            ->whereIn('status', [StockCycleStatus::Purchased->value, StockCycleStatus::InTransit->value, StockCycleStatus::Arrived->value, StockCycleStatus::InPreparation->value, StockCycleStatus::NotReady->value])
            ->tap(fn (Builder $q) => $this->within($q, 'prep_target_on'))
            ->get()
            ->map(fn (StockCycle $cycle): CalendarEvent => new CalendarEvent(
                "prep-{$cycle->id}@dealer-saas",
                $cycle->prep_target_on ?? Carbon::today(),
                $this->t('Ready for sale by: :vehicle', ['vehicle' => $cycle->title()]),
                null,
                $this->cycleUrl($cycle),
                $this->t('Preparation'),
            ))->values()->all();
    }

    /**
     * @return list<CalendarEvent>
     */
    private function repairs(): array
    {
        return RepairOrder::query()->with('stockCycle.vehicle')
            ->whereIn('status', [RepairOrderStatus::Estimate->value, RepairOrderStatus::Approved->value])
            ->tap(fn (Builder $q) => $this->within($q, 'target_on'))
            ->get()
            ->map(fn (RepairOrder $order): CalendarEvent => new CalendarEvent(
                "repair-{$order->id}@dealer-saas",
                $order->target_on ?? Carbon::today(),
                $this->t('Repair due: :vehicle – :work', ['vehicle' => $order->stockCycle->title(), 'work' => $order->description]),
                null,
                $this->cycleUrl($order->stockCycle),
                $this->t('Preparation'),
            ))->values()->all();
    }

    /**
     * @return list<CalendarEvent>
     */
    private function warranties(): array
    {
        return Warranty::query()->with('stockCycle.vehicle')
            ->where('status', WarrantyStatus::Active->value)
            ->tap(fn (Builder $q) => $this->within($q, 'ends_on'))
            ->get()
            ->map(fn (Warranty $warranty): CalendarEvent => new CalendarEvent(
                "warranty-{$warranty->id}@dealer-saas",
                $warranty->ends_on ?? Carbon::today(),
                $this->t('Warranty ends: :vehicle', ['vehicle' => $warranty->stockCycle->title()]),
                $warranty->policy_number !== null ? $this->t('Policy :number', ['number' => $warranty->policy_number]) : null,
                $this->url(WarrantyResource::class, $warranty),
                $this->t('Warranty'),
            ))->values()->all();
    }

    /**
     * @return list<CalendarEvent>
     */
    private function buybacks(): array
    {
        $events = [];
        $obligations = BuybackObligation::query()->with('vehicle')->where('status', BuybackStatus::Open->value)
            ->where(fn (Builder $q) => $q->whereBetween('remind_on', [$this->from, $this->until])->orWhereBetween('due_on', [$this->from, $this->until]))
            ->get();

        foreach ($obligations as $obligation) {
            $vehicle = $obligation->vehicle->displayName();
            $url = BuybackObligationResource::getUrl(panel: 'app', tenant: $this->tenant);

            if ($obligation->remind_on->between($this->from, $this->until)) {
                $events[] = new CalendarEvent("buyback-remind-{$obligation->id}@dealer-saas", $obligation->remind_on, $this->t('Buy-back reminder: :vehicle', ['vehicle' => $vehicle]), $this->t('Buy-back due on :date.', ['date' => $obligation->due_on->format('d.m.Y')]), $url, $this->t('Buy-back'));
            }

            if ($obligation->due_on->between($this->from, $this->until)) {
                $events[] = new CalendarEvent("buyback-due-{$obligation->id}@dealer-saas", $obligation->due_on, $this->t('Buy-back due: :vehicle', ['vehicle' => $vehicle]), null, $url, $this->t('Buy-back'));
            }
        }

        return $events;
    }

    /**
     * @return list<CalendarEvent>
     */
    private function payouts(): array
    {
        return Financing::query()->with(['sale.stockCycle.vehicle', 'partner'])
            ->where('status', FinancingStatus::DocumentsSent->value)
            ->tap(fn (Builder $q) => $this->within($q, 'payout_due_on'))
            ->get()
            ->map(fn (Financing $financing): CalendarEvent => new CalendarEvent(
                "payout-{$financing->id}@dealer-saas",
                $financing->payout_due_on ?? Carbon::today(),
                $this->t('Payout due: :partner for :vehicle', ['partner' => $financing->partner->displayName(), 'vehicle' => $financing->sale->stockCycle->title()]),
                null,
                $this->url(FinancingResource::class, $financing),
                $this->t('Leasing'),
            ))->values()->all();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function within(Builder $query, string $column): void
    {
        $query->whereBetween($query->qualifyColumn($column), [$this->from->toDateString(), $this->until->toDateString()]);
    }

    private function cycleUrl(StockCycle $cycle): string
    {
        return $this->url(StockCycleResource::class, $cycle);
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private function url(string $resource, Model $record): string
    {
        return $resource::getUrl('view', ['record' => $record], panel: 'app', tenant: $this->tenant);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function t(string $key, array $replace = []): string
    {
        return __($key, $replace, $this->locale);
    }
}
