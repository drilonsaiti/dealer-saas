<?php

use App\Domain\Tenancy\Actions\InviteMember;
use App\Domain\Tenancy\Enums\Role;
use App\Filament\App\Pages\Tenancy\EditCompanyProfile;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Filament\Pages\EditProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('lets a dealer user change the language in the profile and uses it on the next request', function () {
    $tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $user = makeMember($tenant, Role::Sales, ['locale' => 'de']);
    useAppPanel($tenant, $user);

    Livewire::test(EditProfile::class)
        ->assertFormFieldExists('locale')
        ->fillForm(['locale' => 'fr'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->locale)->toBe('fr');

    $this->actingAs($user)->get(StockCycleResource::getUrl('index', tenant: $tenant, panel: 'app'))->assertOk()->assertSee('Véhicules');
});

it('lets the platform administrator change the language, independent of APP_LOCALE', function () {
    config(['app.locale' => 'en']);
    $admin = User::factory()->platformAdmin()->create(['locale' => 'de']);
    $this->actingAs($admin);
    Filament::setCurrentPanel('platform');

    Livewire::test(EditProfile::class)
        ->assertFormFieldExists('locale')
        ->fillForm(['locale' => 'it'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($admin->refresh()->locale)->toBe('it');
});

it('refuses a language the app does not offer', function () {
    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel('platform');

    Livewire::test(EditProfile::class)
        ->fillForm(['locale' => 'xx'])
        ->call('save')
        ->assertHasFormErrors(['locale']);
});

it('uses the dealer\'s language for users who set none of their own', function () {
    $tenant = makeDealer(['name' => 'Garage Vevey', 'slug' => 'garage-vevey', 'default_locale' => 'fr']);
    $user = makeMember($tenant, Role::Sales); // locale: null (follow the dealer)

    $this->actingAs($user)
        ->get(StockCycleResource::getUrl('index', tenant: $tenant, panel: 'app'))
        ->assertOk()
        ->assertSee('Véhicules');

    // The dealer switches its language: every user without an own setting follows, at once.
    tenantContext()->bypass(fn () => $tenant->update(['default_locale' => 'it']));

    $this->actingAs($user)
        ->get(StockCycleResource::getUrl('index', tenant: $tenant, panel: 'app'))
        ->assertOk()
        ->assertSee('Veicoli');
});

it('keeps a user\'s own language above the dealer\'s', function () {
    $tenant = makeDealer(['name' => 'Garage Vevey', 'slug' => 'garage-vevey', 'default_locale' => 'fr']);
    $user = makeMember($tenant, Role::Sales, ['locale' => 'de']);

    $this->actingAs($user)
        ->get(StockCycleResource::getUrl('index', tenant: $tenant, panel: 'app'))
        ->assertOk()
        ->assertSee('Fahrzeuge');
});

it('applies a changed dealer language on the company profile right away', function () {
    $tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a', 'default_locale' => 'de']);
    $admin = makeMember($tenant, Role::Administrator);
    useAppPanel($tenant, $admin);

    Livewire::test(EditCompanyProfile::class)
        ->fillForm(['default_locale' => 'fr'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(EditCompanyProfile::getUrl(tenant: $tenant));

    expect(tenantContext()->bypass(fn () => $tenant->fresh()->default_locale))->toBe('fr');
});

it('does not give invited members a language of their own', function () {
    $tenant = makeDealer(['name' => 'Garage Vevey', 'slug' => 'garage-vevey', 'default_locale' => 'fr']);

    $membership = asTenant($tenant, fn () => app(InviteMember::class)($tenant, 'new@example.ch', null, Role::Sales, sendInvitation: false));

    expect($membership->user->locale)->toBeNull();
});
