<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The customer drives away: mileage and date recorded, file "delivered", sale "delivered".
 * Open promises to the customer block it (guard of the transition).
 */
class HandOverVehicle
{
    public function __construct(private readonly TransitionStockCycle $transition) {}

    public function __invoke(Sale $sale, int $mileage, ?string $on = null): Sale
    {
        if (! in_array($sale->status, [SaleStatus::Contracted, SaleStatus::Invoiced], true)) {
            throw new BusinessRuleException(__('Only a contracted sale can be handed over.'));
        }

        $date = $on === null ? Carbon::today() : Carbon::parse($on);

        return DB::transaction(function () use ($sale, $mileage, $date): Sale {
            ($this->transition)($sale->stockCycle, StockCycleStatus::Delivered, data: ['mileage_out' => $mileage, 'on' => $date]);

            $sale->forceFill([
                'status' => SaleStatus::Delivered,
                'delivered_on' => $date,
                'mileage_at_handover' => $mileage,
            ])->save();

            return $sale;
        });
    }
}
