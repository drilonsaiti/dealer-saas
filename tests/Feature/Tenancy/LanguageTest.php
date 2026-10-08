<?php

use App\Domain\Tenancy\Enums\Role;
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
