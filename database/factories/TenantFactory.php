<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'legal_name' => $name.' GmbH',
            'street' => fake()->streetAddress(),
            'zip' => (string) fake()->numberBetween(1000, 9658),
            'city' => fake()->city(),
            'country' => 'CH',
            'default_locale' => 'de',
            'status' => Tenant::STATUS_ACTIVE,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Tenant::STATUS_SUSPENDED]);
    }
}
