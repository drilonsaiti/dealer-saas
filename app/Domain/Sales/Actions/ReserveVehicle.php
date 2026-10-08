<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A customer has chosen the car: the file becomes "reserved" until the given date.
 * At most one active sale per file (also enforced by the database).
 */
class ReserveVehicle
{
    public const DEFAULT_RESERVATION_DAYS = 7;

    public function __construct(
        private readonly SaveSaleTerms $saveTerms,
        private readonly TransitionStockCycle $transition,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(StockCycle $cycle, array $data): Sale
    {
        if (! $cycle->status->canTransitionTo(StockCycleStatus::Reserved)) {
            throw new BusinessRuleException(__('Only vehicles that are ready for sale or listed can be reserved.'));
        }

        try {
            return DB::transaction(function () use ($cycle, $data): Sale {
                $sale = new Sale(['stock_cycle_id' => $cycle->getKey()]);
                $sale->forceFill(['status' => SaleStatus::Reserved]);
                $data['reserved_until'] ??= now()->addDays(self::DEFAULT_RESERVATION_DAYS)->toDateString();

                ($this->saveTerms)($sale, $data);
                ($this->transition)($cycle, StockCycleStatus::Reserved);

                return $sale;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException(__('This vehicle is already reserved or sold.'));
        }
    }
}
