<?php

use App\Domain\Parties\Actions\FindPartyDuplicates;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Models\Party;
use App\Domain\Parties\Support\PhoneNumber;
use App\Domain\Parties\Support\SwissLanguageRegion;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Tenancy\Enums\Role;
use App\Filament\App\Resources\Parties\Pages\CreateParty;
use App\Filament\App\Resources\Parties\Pages\ListParties;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->user = makeMember($this->tenant, Role::Sales);
    $this->actingAs($this->user);
});

it('normalises Swiss phone numbers', function (string $input, ?string $expected) {
    expect(PhoneNumber::normalize($input))->toBe($expected);
})->with([
    ['079 123 45 67', '+41791234567'],
    ['0041 79 123 45 67', '+41791234567'],
    ['+41 (0)79 123 45 67', '+41791234567'],
    ['+49 30 1234567', '+49301234567'],
    ['123', null],
]);

it('proposes the correspondence language from the postcode', function (string $zip, string $locale) {
    expect(SwissLanguageRegion::localeForPostcode($zip))->toBe($locale);
})->with([
    ['3011', 'de'],
    ['1800', 'fr'],
    ['2502', 'de'],
    ['2800', 'fr'],
    ['6900', 'it'],
    ['8001', 'de'],
]);

it('stores the date of birth and ID number encrypted', function () {
    asTenant($this->tenant, function () {
        $party = Party::factory()->create(['birth_date' => '1985-04-12', 'id_doc_number' => 'X1234567']);

        $raw = DB::table('parties')->where('id', $party->id)->first();

        expect($raw->birth_date)->not->toContain('1985')
            ->and($raw->id_doc_number)->not->toContain('X1234567')
            ->and($party->refresh()->birth_date)->toBe('1985-04-12')
            ->and($party->toArray())->not->toHaveKey('birth_date');
    });
});

it('warns about possible duplicates by email, phone or similar name', function () {
    asTenant($this->tenant, function () {
        $existing = Party::factory()->create([
            'first_name' => 'Hans', 'last_name' => 'Müller', 'email' => 'Hans.Mueller@Example.ch', 'mobile' => '079 123 45 67',
        ]);

        $find = app(FindPartyDuplicates::class);

        expect($find(['email' => 'hans.mueller@example.ch']))->toHaveCount(1)
            ->and($find(['mobile' => '+41 79 123 45 67']))->toHaveCount(1)
            ->and($find(['first_name' => 'Hans', 'last_name' => 'Muller']))->toHaveCount(1)
            ->and($find(['first_name' => 'Anna', 'last_name' => 'Rossi']))->toHaveCount(0)
            ->and($find(['email' => 'hans.mueller@example.ch'], ignoreId: $existing->id))->toHaveCount(0);
    });
});

it('creates a company from the contacts screen', function () {
    useAppPanel($this->tenant, $this->user);

    Livewire::test(CreateParty::class)
        ->fillForm([
            'kind' => 'company',
            'roles' => ['supplier'],
            'company_name' => 'Reflex Automobiles Sàrl',
            'zip' => '1004',
            'city' => 'Lausanne',
            'country' => 'CH',
            'locale' => 'fr',
            'email' => 'achat@reflex.example.ch',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $party = Party::sole();

    expect($party->displayName())->toBe('Reflex Automobiles Sàrl')
        ->and($party->hasRole(PartyRole::Supplier))->toBeTrue();

    Livewire::test(ListParties::class)
        ->filterTable('roles', 'supplier')
        ->assertCanSeeTableRecords([$party]);
});

it('keeps contacts that are used in a purchase', function () {
    $purchase = asTenant($this->tenant, fn () => Purchase::factory()->create());

    asTenant($this->tenant, function () use ($purchase) {
        expect($this->user->can('delete', $purchase->seller))->toBeFalse()
            ->and($this->user->can('delete', Party::factory()->create()))->toBeTrue();
    });
});
