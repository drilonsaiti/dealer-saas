<?php

namespace App\Domain\Settings\Rules;

use App\Domain\Settings\Support\Iban;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A normal Swiss or Liechtenstein IBAN (not a QR-IBAN).
 */
class SwissIban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $iban = is_string($value) ? $value : '';

        if (! Iban::isSwissOrLiechtenstein($iban)) {
            $fail(__('Enter a valid Swiss or Liechtenstein IBAN.'));

            return;
        }

        if (Iban::isQrIban($iban)) {
            $fail(__('This is a QR-IBAN. Enter it in the QR-IBAN field and use the normal IBAN here.'));
        }
    }
}
