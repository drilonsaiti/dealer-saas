<?php

namespace App\Domain\Vehicles\Actions;

use App\Domain\Purchasing\Actions\RecordPurchase;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Support\Stammnummer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * "New vehicle": finds the car by Stammnummer (or creates it) and opens a stock cycle.
 *
 * A car the dealer already had (buy-back, leasing return) keeps its vehicle record; its
 * data is completed with what was entered now, without wiping fields left empty.
 */
class RecordVehicle
{
    public function __construct(
        private readonly OpenStockCycle $openStockCycle,
        private readonly RecordPurchase $recordPurchase,
    ) {}

    /**
     * @param  array<string, mixed>  $vehicle  Vehicle attributes
     * @param  array<string, mixed>  $cycle  Cycle attributes (planned_price_rp, list_price_rp, mileage_in, notes)
     * @param  array<string, mixed>|null  $purchase  Purchase attributes when the car is already bought; null = in review
     */
    public function __invoke(array $vehicle, array $cycle = [], ?array $purchase = null): StockCycle
    {
        return DB::transaction(function () use ($vehicle, $cycle, $purchase): StockCycle {
            $record = $this->findOrCreateVehicle($vehicle);

            $stockCycle = ($this->openStockCycle)($record, $cycle);

            if ($purchase !== null) {
                ($this->recordPurchase)($stockCycle, $purchase);
            }

            return $stockCycle->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function findOrCreateVehicle(array $attributes): Vehicle
    {
        $stammnummer = Stammnummer::normalize(Arr::get($attributes, 'stammnummer'));

        $existing = $stammnummer === null ? null : Vehicle::query()->where('stammnummer', $stammnummer)->first();

        if ($existing === null) {
            return Vehicle::create($attributes);
        }

        $existing->fill(array_filter($attributes, fn (mixed $value): bool => filled($value)))->save();

        return $existing;
    }
}
