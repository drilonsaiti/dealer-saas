<?php

namespace Database\Factories;

use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates cycles directly in a status (for tests); real code goes through the actions.
 *
 * @extends Factory<StockCycle>
 */
class StockCycleFactory extends Factory
{
    protected $model = StockCycle::class;

    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'status' => StockCycleStatus::InReview,
            'mileage_in' => $this->faker->numberBetween(10000, 150000),
            'planned_price_rp' => 2_000_000,
        ];
    }

    public function status(StockCycleStatus $status): static
    {
        return $this->state(fn (): array => array_filter([
            'status' => $status,
            'number' => $status === StockCycleStatus::InReview ? null : now()->format('Y').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'purchased_on' => $status === StockCycleStatus::InReview ? null : now()->subDays(40)->toDateString(),
            'file_year' => $status === StockCycleStatus::InReview ? null : (int) now()->subDays(40)->format('Y'),
            'list_price_rp' => in_array($status, [StockCycleStatus::Listed, StockCycleStatus::Reserved, StockCycleStatus::Sold], true) ? 2_190_000 : null,
            'sold_on' => in_array($status, [StockCycleStatus::Sold, StockCycleStatus::Delivered, StockCycleStatus::Archived], true) ? now()->subDays(5)->toDateString() : null,
        ], fn (mixed $value): bool => $value !== null));
    }

    public function delivered(int $daysAgo = 40): static
    {
        return $this->status(StockCycleStatus::Delivered)->state([
            'delivered_on' => now()->subDays($daysAgo)->toDateString(),
            'sold_on' => now()->subDays($daysAgo + 2)->toDateString(),
            'mileage_out' => 160000,
        ]);
    }
}
