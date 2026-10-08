<?php

namespace Database\Factories;

use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commitment>
 */
class CommitmentFactory extends Factory
{
    protected $model = Commitment::class;

    public function definition(): array
    {
        return [
            'stock_cycle_id' => StockCycle::factory(),
            'description' => '4 new summer tyres',
            'estimated_cost_rp' => 80_000,
            'blocks_handover' => true,
        ];
    }
}
