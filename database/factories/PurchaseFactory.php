<?php

namespace Database\Factories;

use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Enums\SellerKind;
use App\Domain\Purchasing\Enums\VatSituation;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    public function definition(): array
    {
        return [
            'stock_cycle_id' => StockCycle::factory(),
            'seller_party_id' => Party::factory(),
            'seller_kind' => SellerKind::Private,
            'contract_on' => now()->subDays(40)->toDateString(),
            'price_rp' => 1_500_000,
            'vat_situation' => VatSituation::PrivateNoVat,
        ];
    }
}
