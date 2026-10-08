<?php

namespace App\Filament\Support;

use App\Support\Money;
use Closure;
use Filament\Forms\Components\TextInput;

/**
 * Amount field: the user types CHF (21'000, 21’000.–, 21000.50), the model receives Rappen.
 */
final class MoneyInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->prefix('CHF')
            ->inputMode('decimal')
            ->placeholder('0.00')
            ->formatStateUsing(fn (mixed $state): ?string => is_int($state) ? Money::toInput($state) : (is_string($state) ? $state : null))
            ->dehydrateStateUsing(fn (mixed $state): ?int => Money::isParsable($state) ? Money::parse($state) : null)
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (! Money::isParsable($value)) {
                    $fail(__('Enter an amount in CHF, for example 21’000.00.'));
                }
            });
    }
}
