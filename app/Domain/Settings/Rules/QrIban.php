<?php

namespace App\Domain\Settings\Rules;

use App\Domain\Settings\Support\Iban;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A QR-IBAN as issued by Swiss banks (institution ID 30000-31999).
 */
class QrIban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $iban = is_string($value) ? $value : '';

        if (! Iban::isSwissOrLiechtenstein($iban)) {
            $fail(__('Enter a valid Swiss or Liechtenstein IBAN.'));

            return;
        }

        if (! Iban::isQrIban($iban)) {
            $fail(__('This is not a QR-IBAN. Your bank issues the QR-IBAN; it has an institution ID between 30000 and 31999.'));
        }
    }
}
