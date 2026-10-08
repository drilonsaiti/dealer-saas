<?php

namespace Database\Factories;

use App\Domain\Parties\Models\Party;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates a sale row directly (for tests); real code goes through ReserveVehicle / ContractSale.
 *
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'stock_cycle_id' => StockCycle::factory(),
            'buyer_party_id' => Party::factory(),
            'status' => SaleStatus::Contracted->value,
            'sale_on' => now()->subDays(3)->toDateString(),
            'price_rp' => 2_190_000,
        ];
    }

    public function status(SaleStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
