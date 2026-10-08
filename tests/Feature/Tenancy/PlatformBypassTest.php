<?php

use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Platform\Resources\Tenants\Pages\EditTenant;
use App\Http\Middleware\EnablePlatformBypass;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Livewire replays the panel's persistent middleware to completion *before* it runs a component
 * action (such as saving a form), so the platform bypass must still be on afterwards or the
 * policies refuse the save with 403.
 */

it('keeps the platform bypass on until the request ends', function () {
    $admin = User::factory()->platformAdmin()->create();
    $request = Request::create('/platform');
    $request->setUserResolver(fn () => $admin);

    app(EnablePlatformBypass::class)->handle($request, fn () => new Response('ok'));

    expect(tenantContext()->isBypassed())->toBeTrue();
});

it('refuses users who are not platform administrators', function () {
    $user = User::factory()->create();
    $request = Request::create('/platform');
    $request->setUserResolver(fn () => $user);

    app(EnablePlatformBypass::class)->handle($request, fn () => new Response('ok'));
})->throws(HttpException::class);

it('lets a platform administrator save a dealer', function () {
    $tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a', 'default_locale' => 'de']);
    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel('platform');
    tenantContext()->enableBypass();

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm(['name' => 'Garage Alpha', 'default_locale' => 'it'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Tenant::query()->find($tenant->getKey())->default_locale)->toBe('it');
});
