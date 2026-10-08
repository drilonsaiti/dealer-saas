<?php

namespace App\Domain\Vehicles\Actions;

use App\Domain\Audit\Models\StatusHistory;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Support\BusinessRuleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opens a new pass of a known vehicle through the dealership (first purchase, buy-back,
 * leasing return). Never creates a second vehicle: a car that comes back gets a new
 * cycle on its existing record. Only one cycle per vehicle can be open at a time.
 */
class OpenStockCycle
{
    /**
     * @param  array<string, mixed>  $attributes  planned_price_rp, list_price_rp, mileage_in, notes, legacy_ref
     */
    public function __invoke(Vehicle $vehicle, array $attributes = []): StockCycle
    {
        $open = $vehicle->openStockCycle()->first();

        if ($open !== null) {
            throw new BusinessRuleException(__('This vehicle is already in stock (file :number).', ['number' => $open->number ?? $open->status->getLabel()]));
        }

        try {
            return DB::transaction(function () use ($vehicle, $attributes): StockCycle {
                $cycle = new StockCycle($attributes);
                $cycle->vehicle()->associate($vehicle);
                $cycle->forceFill(['status' => StockCycleStatus::InReview])->save();

                StatusHistory::record($cycle, null, StockCycleStatus::InReview->value);

                return $cycle;
            });
        } catch (UniqueConstraintViolationException) {
            // Someone opened a cycle for the same car at the same moment; the database index decided.
            throw new BusinessRuleException(__('This vehicle is already in stock.'));
        }
    }
}
