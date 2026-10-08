<?php

namespace App\Domain\Vehicles\Actions;

use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;

/**
 * Archives the current tenant's vehicle files whose delivery is older than the
 * configured number of days (default 30). Run daily per tenant by the scheduler.
 */
class ArchiveDeliveredCycles
{
    public function __construct(private readonly TransitionStockCycle $transition) {}

    public function __invoke(): int
    {
        $archived = 0;

        StockCycle::query()
            ->where('status', StockCycleStatus::Delivered)
            ->whereNotNull('delivered_on')
            ->each(function (StockCycle $cycle) use (&$archived): void {
                if ($this->transition->problems($cycle, StockCycleStatus::Archived) !== []) {
                    return;
                }

                try {
                    ($this->transition)($cycle, StockCycleStatus::Archived);
                    $archived++;
                } catch (BusinessRuleException) {
                    // Guard changed in the meantime; try again tomorrow.
                }
            });

        return $archived;
    }
}
