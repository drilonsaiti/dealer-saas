<?php

namespace App\Domain\Reporting;

use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Support\Carbon;

/**
 * Dashboard figures (spec 3.14) for the current dealer, computed from the records.
 */
class StockReport
{
    /**
     * @return array{count: int, value_rp: int, average_days: int|null, over_30: int, over_60: int, over_90: int, not_ready: int, in_preparation: int, reserved: int}
     */
    public function stock(?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        $cycles = StockCycle::query()->inStock()->get(['id', 'status', 'purchased_on']);
        $days = $cycles->map(fn (StockCycle $cycle): ?int => $cycle->daysInStock($today))->filter(fn (?int $d): bool => $d !== null);

        return [
            'count' => $cycles->count(),
            'value_rp' => $this->stockValue($cycles->pluck('id')->all()),
            'average_days' => $days->isEmpty() ? null : (int) round($days->avg()),
            'over_30' => $days->filter(fn (int $d): bool => $d > 30)->count(),
            'over_60' => $days->filter(fn (int $d): bool => $d > 60)->count(),
            'over_90' => $days->filter(fn (int $d): bool => $d > 90)->count(),
            'not_ready' => $cycles->where('status', StockCycleStatus::NotReady)->count(),
            'in_preparation' => $cycles->where('status', StockCycleStatus::InPreparation)->count(),
            'reserved' => $cycles->where('status', StockCycleStatus::Reserved)->count(),
        ];
    }

    /**
     * Stock value = purchase prices plus costs so far (incl. open promises) of the cars in stock.
     *
     * @param  list<string>  $cycleIds
     */
    public function stockValue(array $cycleIds): int
    {
        if ($cycleIds === []) {
            return 0;
        }

        $purchases = (int) Purchase::query()->whereIn('stock_cycle_id', $cycleIds)->sum('price_rp');
        $costs = (int) Cost::query()
            ->whereIn('stock_cycle_id', $cycleIds)
            ->whereHas('category', fn ($query) => $query->where('counts_toward_margin', true))
            ->sum('gross_rp');
        $promises = (int) Commitment::query()->whereIn('stock_cycle_id', $cycleIds)->whereNull('cost_id')->sum('estimated_cost_rp');

        return $purchases + $costs + $promises;
    }

    /**
     * Sales count, revenue and margin per month of the sale date, most recent last.
     *
     * @return list<array{month: string, count: int, revenue_rp: int, margin_rp: int, provisional: bool}>
     */
    public function salesPerMonth(int $months = 12, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $from = $today->copy()->startOfMonth()->subMonths($months - 1);
        $calculate = app(CalculateMargin::class);

        $sales = Sale::query()
            ->active()
            ->whereIn('status', [SaleStatus::Contracted, SaleStatus::Invoiced, SaleStatus::Delivered])
            ->where('sale_on', '>=', $from->toDateString())
            ->with('stockCycle')
            ->get();

        $rows = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $from->copy()->addMonths($i)->format('Y-m');
            $rows[$month] = ['month' => $month, 'count' => 0, 'revenue_rp' => 0, 'margin_rp' => 0, 'provisional' => false];
        }

        foreach ($sales as $sale) {
            $month = $sale->sale_on?->format('Y-m');

            if ($month === null || ! isset($rows[$month])) {
                continue;
            }

            $margin = $calculate($sale->stockCycle);
            $rows[$month]['count']++;
            $rows[$month]['revenue_rp'] += $margin->revenueRp;
            $rows[$month]['margin_rp'] += (int) $margin->marginRp();
            $rows[$month]['provisional'] = $rows[$month]['provisional'] || $margin->isProvisional;
        }

        return array_values($rows);
    }

    /**
     * Promises to customers not yet kept, and reservations running out within the given days.
     *
     * @return array{open_promises: int, expiring_reservations: int, open_seller_payments: int}
     */
    public function openItems(int $reservationDays = 2, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        return [
            'open_promises' => Commitment::query()->open()->count(),
            'expiring_reservations' => Sale::query()
                ->where('status', SaleStatus::Reserved)
                ->whereNotNull('reserved_until')
                ->where('reserved_until', '<=', $today->copy()->addDays($reservationDays)->toDateString())
                ->count(),
            'open_seller_payments' => StockCycle::query()
                ->whereIn('status', StockCycleStatus::openValues())
                ->whereHas('purchase', fn ($query) => $query->where('payment_status', '<>', 'paid'))
                ->count(),
        ];
    }
}
