<?php

namespace Database\Factories;

use App\Domain\Vehicles\Enums\BodyType;
use App\Domain\Vehicles\Enums\FuelType;
use App\Domain\Vehicles\Enums\Transmission;
use App\Domain\Vehicles\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        $cars = [
            ['Toyota', 'Corolla', '1.8 Hybrid', BodyType::Hatchback, FuelType::Hybrid, 90],
            ['BMW', 'X3', 'xDrive30i', BodyType::Suv, FuelType::Petrol, 185],
            ['VW', 'Golf', '2.0 TDI', BodyType::Hatchback, FuelType::Diesel, 110],
            ['Hyundai', 'Tucson', '1.6 T-GDi', BodyType::Suv, FuelType::Hybrid, 169],
            ['Skoda', 'Octavia', 'Combi 2.0 TDI', BodyType::Estate, FuelType::Diesel, 110],
        ];

        [$make, $model, $variant, $body, $fuel, $kw] = $this->faker->randomElement($cars);

        return [
            'stammnummer' => (string) $this->faker->unique()->numberBetween(100000000, 999999999),
            'vin' => strtoupper($this->faker->bothify('WBA#######?######')),
            'make' => $make,
            'model' => $model,
            'variant' => $variant,
            'body_type' => $body,
            'fuel' => $fuel,
            'transmission' => Transmission::Automatic,
            'power_kw' => $kw,
            'first_registration_on' => $this->faker->dateTimeBetween('-8 years', '-1 year')->format('Y-m-d'),
            'color_exterior' => $this->faker->randomElement(['Schwarz', 'Weiss', 'Grau', 'Blau']),
        ];
    }

    public function withoutStammnummer(): static
    {
        return $this->state(['stammnummer' => null]);
    }
}
