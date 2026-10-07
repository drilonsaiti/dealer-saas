<?php

namespace Database\Factories;

use App\Domain\Settings\Enums\NumberSequenceKey;
use App\Domain\Settings\Models\NumberSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NumberSequence>
 */
class NumberSequenceFactory extends Factory
{
    protected $model = NumberSequence::class;

    public function definition(): array
    {
        return [
            'key' => NumberSequenceKey::Invoice,
            'pattern' => NumberSequenceKey::Invoice->defaultPattern(),
            'next_value' => 1,
            'reset_yearly' => false,
        ];
    }
}
