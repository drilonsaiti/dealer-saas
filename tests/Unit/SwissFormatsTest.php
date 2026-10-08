<?php

use App\Domain\Vehicles\Support\Stammnummer;
use App\Domain\Vehicles\Support\Vin;
use App\Support\Money;
use App\Support\SwissFormat;

it('normalises Stammnummer variants to nine digits', function (string $input, ?string $expected) {
    expect(Stammnummer::normalize($input))->toBe($expected);
})->with([
    ['683.737.537', '683737537'],
    ['683 737 537', '683737537'],
    ['683737537', '683737537'],
    ['000.653.461.306', '653461306'],
    ['', null],
]);

it('formats the Stammnummer as 683.737.537', function () {
    expect(Stammnummer::format('683737537'))->toBe('683.737.537')
        ->and(Stammnummer::isValid('683.737.537'))->toBeTrue()
        ->and(Stammnummer::isValid('68373753'))->toBeFalse()
        ->and(Stammnummer::isValid('1.000.653.461.306'))->toBeFalse();
});

it('validates VINs', function () {
    expect(Vin::normalize(' wba-1234567890abcde '))->toBe('WBA1234567890ABCDE')
        ->and(Vin::isValid('WBAUZ71010VN12345'))->toBeTrue()
        ->and(Vin::isValid('WBAUZ71010VN1234O'))->toBeFalse() // the letter O never occurs
        ->and(Vin::isValid('WBAUZ71010'))->toBeFalse();
});

it('formats money the Swiss way', function () {
    expect(Money::format(2_100_000))->toBe('CHF 21’000.00')
        ->and(Money::format(-450_000))->toBe('CHF -4’500.00')
        ->and(Money::format(5, withCurrency: false))->toBe('0.05')
        ->and(Money::format(null))->toBe('–');
});

it('parses amounts as people type them', function (string|int $input, int $rappen) {
    expect(Money::parse($input))->toBe($rappen);
})->with([
    ['21000', 2_100_000],
    ["21'000", 2_100_000],
    ['21’000.–', 2_100_000],
    ['21 000.50', 2_100_050],
    ['21000,5', 2_100_050],
    ['CHF 21’000.00', 2_100_000],
    ['-4500', -450_000],
    [12, 1_200],
]);

it('rejects text that is not an amount', function () {
    expect(Money::isParsable('zwanzig'))->toBeFalse()
        ->and(Money::isParsable('21.005'))->toBeFalse()
        ->and(fn () => Money::parse('abc'))->toThrow(InvalidArgumentException::class);
});

it('formats mileage and dates', function () {
    expect(SwissFormat::mileage(79310))->toBe('79’310 km')
        ->and(SwissFormat::date(new DateTimeImmutable('2026-07-14')))->toBe('14.07.2026');
});
