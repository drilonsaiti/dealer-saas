<?php

namespace Database\Factories;

use App\Domain\Vehicles\Enums\TyreSeason;
use App\Domain\Vehicles\Models\TyreSet;
use App\Domain\Vehicles\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TyreSet>
 */
class TyreSetFactory extends Factory
{
    protected $model = TyreSet::class;

    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'season' => TyreSeason::Winter,
            'dimension' => '225/45 R17',
            'tread_mm' => '6.5',
            'on_rims' => true,
        ];
    }
}
