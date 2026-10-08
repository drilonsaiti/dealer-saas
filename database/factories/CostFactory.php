<?php

namespace Database\Factories;

use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Needs the dealer's cost categories (InstallDefaultCostCategories) in the current tenant.
 *
 * @extends Factory<Cost>
 */
class CostFactory extends Factory
{
    protected $model = Cost::class;

    public function definition(): array
    {
        return [
            'stock_cycle_id' => StockCycle::factory(),
            'category_id' => fn (): string => CostCategory::query()->where('key', 'repair')->value('id'),
            'incurred_on' => now()->subDays(10)->toDateString(),
            'description' => 'Service',
            'gross_rp' => 45_000,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(['status' => 'confirmed']);
    }
}
