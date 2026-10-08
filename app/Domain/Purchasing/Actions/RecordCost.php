<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Adds or corrects a cost line. A confirmed cost is final (only a new correcting line can
 * change the margin), and closed vehicle files take no new costs.
 * Linking a cost to a commitment replaces the commitment's estimate in the margin.
 */
class RecordCost
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data, ?Cost $cost = null): Cost
    {
        if ($cost?->isConfirmed()) {
            throw new BusinessRuleException(__('A confirmed cost cannot be changed. Add a correction line instead.'));
        }

        $cycleId = $data['stock_cycle_id'] ?? $cost?->stock_cycle_id;

        if ($cycleId !== null && StockCycle::query()->findOrFail($cycleId)->isLocked()) {
            throw new BusinessRuleException(__('This vehicle file is closed and cannot be changed.'));
        }

        return DB::transaction(function () use ($data, $cost): Cost {
            $cost ??= new Cost;
            $cost->fill($data)->save();

            if ($cost->commitment_id !== null) {
                Commitment::query()->whereKey($cost->commitment_id)->update(['cost_id' => $cost->getKey()]);
            }

            return $cost;
        });
    }
}
