<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The deal is done: the reservation (or a direct sale) becomes a contracted sale, the file
 * becomes "sold", and a trade-in car gets its own vehicle file.
 */
class ContractSale
{
    public function __construct(
        private readonly SaveSaleTerms $saveTerms,
        private readonly ConfirmTradeIn $confirmTradeIn,
        private readonly TransitionStockCycle $transition,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(StockCycle $cycle, array $data): Sale
    {
        if (! $cycle->status->canTransitionTo(StockCycleStatus::Sold)) {
            throw new BusinessRuleException(__('Only vehicles that are ready for sale, listed or reserved can be sold.'));
        }

        try {
            return DB::transaction(function () use ($cycle, $data): Sale {
                /** @var Sale|null $sale */
                $sale = Sale::query()->active()->where('stock_cycle_id', $cycle->getKey())->lockForUpdate()->first();

                if ($sale !== null && $sale->status !== SaleStatus::Reserved) {
                    throw new BusinessRuleException(__('This vehicle is already sold.'));
                }

                $sale ??= new Sale(['stock_cycle_id' => $cycle->getKey()]);
                $data['sale_on'] = filled($data['sale_on'] ?? null) ? $data['sale_on'] : now()->toDateString();
                $sale->forceFill(['status' => SaleStatus::Contracted, 'reserved_until' => null]);

                ($this->saveTerms)($sale, $data);

                $tradeIn = $sale->tradeIn()->first();

                if ($tradeIn !== null && ! $tradeIn->isConfirmed()) {
                    ($this->confirmTradeIn)($tradeIn);
                }

                ($this->transition)($cycle, StockCycleStatus::Sold, data: ['on' => Carbon::parse($data['sale_on'])]);

                return $sale;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException(__('This vehicle is already reserved or sold.'));
        }
    }
}
