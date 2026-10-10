<?php

namespace App\Domain\Preparation\Actions;

use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Preparation is finished: the file becomes "ready for sale". Refused while a repair order
 * that blocks the release is still open (the transition guard checks the same).
 */
class ReleaseForSale
{
    public function __construct(private readonly TransitionStockCycle $transition) {}

    public function __invoke(StockCycle $cycle): StockCycle
    {
        if (! $cycle->status->canTransitionTo(StockCycleStatus::ReadyForSale)) {
            throw new BusinessRuleException(__('A vehicle file in status ":status" cannot be released for sale.', ['status' => $cycle->status->getLabel()]));
        }

        return DB::transaction(function () use ($cycle): StockCycle {
            ($this->transition)($cycle, StockCycleStatus::ReadyForSale);
            $cycle->forceFill(['released_for_sale_at' => now(), 'released_for_sale_by' => Auth::id()])->save();

            return $cycle;
        });
    }
}
