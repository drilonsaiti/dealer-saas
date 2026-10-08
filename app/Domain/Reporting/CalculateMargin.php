<?php

namespace App\Domain\Reporting;

use App\Domain\Purchasing\Enums\CostStatus;
use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Sales\Models\Sale;
use App\Domain\Vehicles\Models\StockCycle;

/**
 * Builds the margin of a vehicle file from its purchase, costs, promises and sale.
 * Before the sale the expected revenue is the list price (or the planned price).
 */
class CalculateMargin
{
    public function __invoke(StockCycle $cycle): Margin
    {
        $costs = Cost::query()
            ->where('stock_cycle_id', $cycle->getKey())
            ->whereHas('category', fn ($query) => $query->where('counts_toward_margin', true))
            ->get(['gross_rp', 'status', 'is_estimate']);

        $confirmed = (int) $costs->filter(fn (Cost $cost): bool => $cost->status === CostStatus::Confirmed)->sum('gross_rp');
        $open = (int) $costs->filter(fn (Cost $cost): bool => $cost->status !== CostStatus::Confirmed)->sum('gross_rp');

        // A promise counts with its estimate until a real cost replaces it.
        $promises = (int) Commitment::query()
            ->where('stock_cycle_id', $cycle->getKey())
            ->whereNull('cost_id')
            ->sum('estimated_cost_rp');

        $sale = Sale::query()->active()->with('items')->where('stock_cycle_id', $cycle->getKey())->first();

        [$revenue, $basis] = match (true) {
            $sale !== null => [$sale->totalRp(), Margin::BASIS_SALE],
            $cycle->list_price_rp !== null => [$cycle->list_price_rp, Margin::BASIS_LIST_PRICE],
            $cycle->planned_price_rp !== null => [$cycle->planned_price_rp, Margin::BASIS_PLANNED_PRICE],
            default => [0, Margin::BASIS_NONE],
        };

        $purchase = $cycle->purchase()->value('price_rp');

        return new Margin(
            revenueRp: $revenue,
            revenueBasis: $basis,
            purchaseRp: (int) ($purchase ?? 0),
            confirmedCostsRp: $confirmed,
            openCostsRp: $open,
            openPromisesRp: $promises,
            isProvisional: $basis !== Margin::BASIS_SALE || $open > 0 || $promises > 0 || $purchase === null,
        );
    }
}
