<?php

namespace App\Domain\Vehicles\Events;

use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after every committed status change; later modules (listings, checklists,
 * warranties) react to it instead of being called from the transition itself.
 */
class StockCycleStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly StockCycle $cycle,
        public readonly ?StockCycleStatus $from,
        public readonly StockCycleStatus $to,
        public readonly ?string $reason,
    ) {}
}
