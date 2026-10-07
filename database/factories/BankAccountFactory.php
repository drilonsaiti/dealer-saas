<?php

namespace Database\Factories;

use App\Domain\Settings\Models\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            'label' => 'Hauptkonto',
            'bank_name' => 'Valiant Bank AG',
            'iban' => 'CH2106300505282032675',
            'qr_iban' => 'CH8130024505282032675',
            'bic' => 'VABECH22XXX',
            'currency' => 'CHF',
            'is_default' => true,
        ];
    }
}
