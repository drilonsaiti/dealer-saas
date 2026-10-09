<?php

namespace App\Domain\Financing\Actions;

use App\Domain\Financing\Enums\BuybackStatus;
use App\Domain\Financing\Models\BuybackObligation;
use App\Domain\Purchasing\Actions\RecordPurchase;
use App\Domain\Purchasing\Enums\PurchaseType;
use App\Domain\Purchasing\Enums\SellerKind;
use App\Domain\Vehicles\Actions\OpenStockCycle;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The car comes back from the bank at lease end: a new vehicle file on the same vehicle,
 * bought from the bank as a buy-back at the agreed amount. Or the bank releases the dealer.
 */
class ExerciseBuyback
{
    public function __construct(
        private readonly OpenStockCycle $openCycle,
        private readonly RecordPurchase $purchase,
    ) {}

    public function __invoke(BuybackObligation $obligation, string $on, ?int $mileage = null): StockCycle
    {
        if ($obligation->status !== BuybackStatus::Open) {
            throw new BusinessRuleException(__('This buy-back obligation is already settled.'));
        }

        return DB::transaction(function () use ($obligation, $on, $mileage): StockCycle {
            $cycle = ($this->openCycle)($obligation->vehicle, ['mileage_in' => $mileage]);

            ($this->purchase)($cycle, [
                'seller_party_id' => $obligation->financing->partner_party_id,
                'seller_kind' => SellerKind::Company->value,
                'purchase_type' => PurchaseType::Buyback->value,
                'contract_on' => Carbon::parse($on)->toDateString(),
                'price_rp' => $obligation->amount_rp,
                'mileage' => $mileage,
            ]);

            $obligation->forceFill(['status' => BuybackStatus::Exercised, 'settled_on' => Carbon::parse($on)->toDateString(), 'stock_cycle_id' => $cycle->getKey()])->save();

            return $cycle->refresh();
        });
    }

    public function release(BuybackObligation $obligation, ?string $note = null): void
    {
        if ($obligation->status !== BuybackStatus::Open) {
            throw new BusinessRuleException(__('This buy-back obligation is already settled.'));
        }

        $obligation->forceFill(['status' => BuybackStatus::Released, 'settled_on' => Carbon::today()->toDateString(), 'notes' => $note])->save();
    }
}
