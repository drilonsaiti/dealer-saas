<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Enums\SellerKind;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records (or corrects) the purchase of a vehicle file. A file still "in review" becomes
 * "purchased" and gets its number; the purchase date sets the file year.
 */
class RecordPurchase
{
    public function __construct(private readonly TransitionStockCycle $transition) {}

    /**
     * @param  array<string, mixed>  $data  Purchase attributes (seller_party_id, seller_kind, contract_on, price_rp, ...)
     */
    public function __invoke(StockCycle $cycle, array $data): Purchase
    {
        if ($cycle->isLocked()) {
            throw new BusinessRuleException(__('This vehicle file is closed and cannot be changed.'));
        }

        $problems = $this->problems($data);

        if ($problems !== []) {
            throw BusinessRuleException::because($problems);
        }

        return DB::transaction(function () use ($cycle, $data): Purchase {
            /** @var Purchase $purchase */
            $purchase = Purchase::query()->firstOrNew(['stock_cycle_id' => $cycle->getKey()]);
            $purchase->fill($data)->save();

            $this->giveSellerItsRole($purchase);

            $contractOn = Carbon::parse($purchase->contract_on);
            $cycle->forceFill([
                'purchased_on' => $contractOn,
                'file_year' => (int) $contractOn->format('Y'),
                'mileage_in' => $cycle->mileage_in ?? $purchase->mileage,
            ])->save();

            if ($cycle->status === StockCycleStatus::InReview) {
                ($this->transition)($cycle, StockCycleStatus::Purchased);
            }

            return $purchase;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function problems(array $data): array
    {
        $problems = [];

        if (blank($data['seller_party_id'] ?? null)) {
            $problems[] = __('Choose the seller.');
        }

        if (! isset($data['price_rp']) || ! is_int($data['price_rp']) || $data['price_rp'] < 0) {
            $problems[] = __('Enter the purchase price.');
        }

        if (blank($data['contract_on'] ?? null)) {
            $problems[] = __('Enter the purchase date.');
        }

        return $problems;
    }

    private function giveSellerItsRole(Purchase $purchase): void
    {
        $seller = $purchase->seller;

        if (! $seller instanceof Party) {
            return;
        }

        $seller->addRole($purchase->seller_kind === SellerKind::Private ? PartyRole::PrivateSeller : PartyRole::Supplier);

        if ($seller->isDirty('roles')) {
            $seller->save();
        }
    }
}
