<?php

use App\Domain\Settings\Rules\QrIban;
use App\Domain\Settings\Rules\SwissIban;
use App\Domain\Settings\Support\Iban;
use Illuminate\Support\Facades\Validator;

// Real account numbers from Aziri's invoices (Valiant Bank) and a warranty provider.
dataset('swiss ibans', [
    'Valiant IBAN with spaces' => ['CH21 0630 0505 2820 3267 5', true, false],
    'Valiant QR-IBAN' => ['CH81 3002 4505 2820 3267 5', true, true],
    'SuisseFox IBAN' => ['CH0806300508115597491', true, false],
    'typo in last digit' => ['CH21 0630 0505 2820 3267 6', false, false],
    'German IBAN' => ['DE89370400440532013000', false, false],
]);

it('recognises Swiss IBANs and QR-IBANs', function (string $iban, bool $swiss, bool $qr) {
    expect(Iban::isSwissOrLiechtenstein($iban))->toBe($swiss)
        ->and(Iban::isQrIban($iban))->toBe($qr);
})->with('swiss ibans');

it('normalises and formats IBANs', function () {
    expect(Iban::normalize(' ch21 0630-0505 2820 3267 5 '))->toBe('CH2106300505282032675')
        ->and(Iban::format('CH2106300505282032675'))->toBe('CH21 0630 0505 2820 3267 5');
});

it('rejects a QR-IBAN in the normal IBAN field and vice versa', function () {
    expect(Validator::make(['v' => 'CH8130024505282032675'], ['v' => [new SwissIban]])->fails())->toBeTrue()
        ->and(Validator::make(['v' => 'CH2106300505282032675'], ['v' => [new SwissIban]])->passes())->toBeTrue()
        ->and(Validator::make(['v' => 'CH2106300505282032675'], ['v' => [new QrIban]])->fails())->toBeTrue()
        ->and(Validator::make(['v' => 'CH8130024505282032675'], ['v' => [new QrIban]])->passes())->toBeTrue();
});
