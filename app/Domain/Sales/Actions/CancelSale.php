<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Reservation withdrawn or sale cancelled: the car is for sale again (back step with reason).
 * A confirmed trade-in whose file has not moved on yet is cancelled with it.
 */
class CancelSale
{
    public function __construct(private readonly TransitionStockCycle $transition) {}

    public function __invoke(Sale $sale, string $reason): Sale
    {
        if (blank($reason)) {
            throw new BusinessRuleException(__('Please give a reason for this status change.'));
        }

        if (! in_array($sale->status, [SaleStatus::Reserved, SaleStatus::Contracted], true)) {
            throw new BusinessRuleException(__('Only reserved or contracted sales can be cancelled here.'));
        }

        return DB::transaction(function () use ($sale, $reason): Sale {
            $sale->forceFill([
                'status' => SaleStatus::Cancelled,
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
            ])->save();

            ($this->transition)($sale->stockCycle, StockCycleStatus::ReadyForSale, $reason);

            $tradeInCycle = $sale->tradeIn?->purchaseCycle;

            if ($tradeInCycle !== null && $tradeInCycle->status === StockCycleStatus::Purchased) {
                ($this->transition)($tradeInCycle, StockCycleStatus::Cancelled, __('Sale :number cancelled: :reason', [
                    'number' => $sale->stockCycle->number ?? '',
                    'reason' => $reason,
                ]));
            }

            return $sale;
        });
    }
}
