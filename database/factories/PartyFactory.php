<?php

namespace Database\Factories;

use App\Domain\Parties\Enums\PartyKind;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    protected $model = Party::class;

    public function definition(): array
    {
        return [
            'kind' => PartyKind::Person,
            'roles' => [PartyRole::Customer],
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'street' => $this->faker->streetAddress(),
            'zip' => '3011',
            'city' => 'Bern',
            'email' => $this->faker->unique()->safeEmail(),
            'mobile' => '079 '.$this->faker->numerify('### ## ##'),
            'locale' => 'de',
        ];
    }

    public function company(string $name = 'Reflex Automobiles Sàrl', PartyRole $role = PartyRole::Supplier): static
    {
        return $this->state([
            'kind' => PartyKind::Company,
            'roles' => [$role],
            'company_name' => $name,
            'first_name' => null,
            'last_name' => null,
            'zip' => '1004',
            'city' => 'Lausanne',
            'locale' => 'fr',
        ]);
    }
}
