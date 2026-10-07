<?php

use App\Domain\Settings\Actions\IssueNumber;
use App\Domain\Settings\Enums\NumberSequenceKey;
use App\Domain\Settings\Models\NumberSequence;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Enums\Role;
use App\Filament\App\Resources\NumberSequences\Pages\ManageNumberSequences;
use Livewire\Livewire;

function issue(NumberSequenceKey $key = NumberSequenceKey::Invoice, ?int $year = null): string
{
    return app(IssueNumber::class)($key, $year);
}

it('formats numbers from the pattern', function (string $pattern, int $value, string $expected) {
    $sequence = new NumberSequence(['pattern' => $pattern]);

    expect($sequence->format($value, 2026))->toBe($expected);
})->with([
    ['RE-{00000}', 271, 'RE-00271'],
    ['{YYYY}-{0000}', 107, '2026-0107'],
    ['GS{YY}/{000}', 5, 'GS26/005'],
    ['KV-{0}', 12345, 'KV-12345'],
]);

it('rejects patterns without a running number', function () {
    expect(NumberSequence::isValidPattern('RE-2026'))->toBeFalse()
        ->and(NumberSequence::isValidPattern('RE-{000}'))->toBeTrue();
});

it('sets up the default number ranges for a new dealer', function () {
    $tenant = app(CreateTenant::class)(['name' => 'Garage Neu'], 'chef@garage-neu.example.ch', sendInvitation: false);

    $keys = asTenant($tenant, fn () => NumberSequence::pluck('key')->map->value->sort()->values()->all());

    expect($keys)->toBe(collect(NumberSequenceKey::cases())->map->value->sort()->values()->all());
});

it('issues consecutive numbers and continues after a migration', function () {
    $tenant = makeTenant();

    asTenant($tenant, function () {
        NumberSequence::factory()->create(['next_value' => 271]); // last bexio invoice was RE-00270

        expect(issue())->toBe('RE-00271')
            ->and(issue())->toBe('RE-00272')
            ->and(NumberSequence::first()->issued_count)->toBe(2);
    });
});

it('restarts yearly numbering in a new year', function () {
    $tenant = makeTenant();

    asTenant($tenant, function () {
        NumberSequence::factory()->create([
            'key' => NumberSequenceKey::StockCycle,
            'pattern' => '{YYYY}-{0000}',
            'reset_yearly' => true,
        ]);

        expect(issue(NumberSequenceKey::StockCycle, 2026))->toBe('2026-0001')
            ->and(issue(NumberSequenceKey::StockCycle, 2026))->toBe('2026-0002')
            ->and(issue(NumberSequenceKey::StockCycle, 2027))->toBe('2027-0001');
    });
});

it('keeps each dealer\'s numbering separate', function () {
    $a = makeTenant();
    $b = makeTenant();

    asTenant($a, fn () => NumberSequence::factory()->create(['next_value' => 100]));
    asTenant($b, fn () => NumberSequence::factory()->create(['next_value' => 1]));

    expect(asTenant($a, fn () => issue()))->toBe('RE-00100')
        ->and(asTenant($b, fn () => issue()))->toBe('RE-00001')
        ->and(asTenant($a, fn () => issue()))->toBe('RE-00101');
});

it('gives the number back when the surrounding transaction fails', function () {
    $tenant = makeTenant();

    asTenant($tenant, function () {
        NumberSequence::factory()->create(['next_value' => 10]);

        try {
            DB::transaction(function () {
                issue();
                throw new RuntimeException('Saving the invoice failed');
            });
        } catch (RuntimeException) {
        }

        expect(issue())->toBe('RE-00010');
    });
});

it('does not let the next number go down once numbers were issued', function () {
    $tenant = makeTenant();
    $user = makeMember($tenant, Role::Accounting);

    $sequence = asTenant($tenant, function () {
        $sequence = NumberSequence::factory()->create(['next_value' => 50]);
        issue();

        return $sequence->fresh();
    });

    useAppPanel($tenant, $user);

    Livewire::test(ManageNumberSequences::class)
        ->callTableAction('edit', $sequence, data: ['next_value' => 20, 'pattern' => 'RE-{00000}'])
        ->assertHasTableActionErrors(['next_value']);

    Livewire::test(ManageNumberSequences::class)
        ->callTableAction('edit', $sequence, data: ['next_value' => 300, 'pattern' => 'RE-{00000}'])
        ->assertHasNoTableActionErrors();

    expect(asTenant($tenant, fn () => issue()))->toBe('RE-00300');
});
