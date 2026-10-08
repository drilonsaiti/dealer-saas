<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Parties\Enums\PartyKind;
use App\Domain\Purchasing\Actions\RecordPurchase;
use App\Domain\Purchasing\Enums\PaymentStatus;
use App\Domain\Purchasing\Enums\PurchaseType;
use App\Domain\Purchasing\Enums\SellerKind;
use App\Domain\Purchasing\Enums\VatSituation;
use App\Domain\Sales\Models\TradeIn;
use App\Domain\Vehicles\Actions\FindVehicleDuplicates;
use App\Domain\Vehicles\Actions\OpenStockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Spec rule 5: confirming a trade-in finds the car (Stammnummer, then VIN) or creates it,
 * and opens a new file with purchase type "trade-in", bought from the sale's buyer for the
 * trade-in value. The new file is linked to the sale.
 */
class ConfirmTradeIn
{
    public function __construct(
        private readonly FindVehicleDuplicates $duplicates,
        private readonly OpenStockCycle $openStockCycle,
        private readonly RecordPurchase $recordPurchase,
    ) {}

    public function __invoke(TradeIn $tradeIn): TradeIn
    {
        if ($tradeIn->isConfirmed()) {
            return $tradeIn;
        }

        return DB::transaction(function () use ($tradeIn): TradeIn {
            $sale = $tradeIn->sale;
            $buyer = $sale->buyer;
            $vehicle = $this->findOrCreateVehicle($tradeIn->vehicle_data);

            if ($vehicle->openStockCycle()->exists()) {
                throw new BusinessRuleException(__('The trade-in vehicle is already in stock.'));
            }

            $cycle = ($this->openStockCycle)($vehicle, ['mileage_in' => $tradeIn->mileage]);
            $sellerKind = $buyer->kind === PartyKind::Company ? SellerKind::Company : SellerKind::Private;

            ($this->recordPurchase)($cycle, [
                'seller_party_id' => $buyer->getKey(),
                'seller_kind' => $sellerKind,
                'purchase_type' => PurchaseType::TradeIn,
                'contract_on' => ($sale->sale_on ?? now())->toDateString(),
                'price_rp' => $tradeIn->value_rp,
                'vat_situation' => $sellerKind === SellerKind::Private ? VatSituation::PrivateNoVat : VatSituation::Unknown,
                'mileage' => $tradeIn->mileage,
                'payoff_rp' => $tradeIn->payoff_rp > 0 ? $tradeIn->payoff_rp : null,
                'payoff_party_id' => $tradeIn->payoff_party_id,
                'known_defects' => $tradeIn->condition_notes,
                'payment_status' => PaymentStatus::Paid, // settled against the sale
            ]);

            $tradeIn->forceFill(['purchase_cycle_id' => $cycle->getKey(), 'confirmed_at' => now()])->save();

            return $tradeIn;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findOrCreateVehicle(array $data): Vehicle
    {
        $vehicle = $this->duplicates->byStammnummer($data['stammnummer'] ?? null)
            ?? $this->duplicates->byVin($data['vin'] ?? null)->first();

        if ($vehicle !== null) {
            $vehicle->fill(array_filter($data, fn (mixed $value): bool => filled($value)))->save();

            return $vehicle;
        }

        return Vehicle::create(array_filter($data, fn (mixed $value): bool => filled($value)));
    }
}
