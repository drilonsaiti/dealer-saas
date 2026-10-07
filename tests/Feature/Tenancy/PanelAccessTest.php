<?php

use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Role;
use App\Filament\App\Resources\BankAccounts\BankAccountResource;
use App\Filament\App\Resources\BankAccounts\Pages\ManageBankAccounts;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->a = makeTenant(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->b = makeTenant(['name' => 'Garage B', 'slug' => 'garage-b']);

    $this->accountA = asTenant($this->a, fn () => BankAccount::factory()->create(['label' => 'Konto A']));
    $this->accountB = asTenant($this->b, fn () => BankAccount::factory()->create(['label' => 'Konto B']));
});

it('lets a member open their own dealer', function () {
    $user = makeMember($this->a, Role::Sales);

    $this->actingAs($user)
        ->get(BankAccountResource::getUrl('index', tenant: $this->a, panel: 'app'))
        ->assertOk()
        ->assertSee('Konto A')
        ->assertDontSee('Konto B');
});

it('returns 404 when a member opens another dealer\'s URL', function () {
    $user = makeMember($this->a, Role::Sales);

    $this->actingAs($user)
        ->get('/app/garage-b/bank-accounts')
        ->assertNotFound();
});

it('hides settings from read-only users', function () {
    $user = makeMember($this->a, Role::ReadOnly);

    $this->actingAs($user)
        ->get('/app/garage-a/bank-accounts')
        ->assertForbidden();
});

it('blocks users who were deactivated', function () {
    $user = makeMember($this->a, Role::Sales);
    asTenant($this->a, fn () => $user->memberships()->update(['is_active' => false]));

    $this->actingAs($user)
        ->get('/app/garage-a')
        ->assertForbidden();
});

it('blocks suspended dealers', function () {
    $user = makeMember($this->a, Role::Sales);
    tenantContext()->bypass(fn () => $this->a->update(['status' => 'suspended']));

    $this->actingAs($user)
        ->get('/app/garage-a')
        ->assertForbidden();
});

it('forces administrators to set up two-factor authentication', function () {
    $admin = makeMember($this->a, Role::Administrator);

    $this->actingAs($admin)
        ->get('/app/garage-a/bank-accounts')
        ->assertRedirectContains('multi-factor-authentication');
});

it('does not force two-factor authentication on sales unless the dealer requires it', function () {
    $sales = makeMember($this->a, Role::Sales);

    expect($sales->requiresMultiFactorAuthentication())->toBeFalse();

    tenantContext()->bypass(fn () => $this->a->update(['settings' => ['security' => ['require_mfa_for_all' => true]]]));

    expect($sales->fresh()->requiresMultiFactorAuthentication())->toBeTrue();
});

it('keeps the platform panel for platform administrators only', function () {
    $user = makeMember($this->a, Role::Administrator);

    $this->actingAs($user)->get('/platform')->assertForbidden();

    $platformAdmin = User::factory()->platformAdmin()->create();

    $this->actingAs($platformAdmin)
        ->get('/platform')
        ->assertRedirectContains('multi-factor-authentication');
});

it('lists only the current dealer\'s bank accounts in the table', function () {
    $user = makeMember($this->a, Role::Sales);
    useAppPanel($this->a, $user);

    Livewire::test(ManageBankAccounts::class)
        ->assertCanSeeTableRecords([$this->accountA])
        ->assertCanNotSeeTableRecords([$this->accountB]);
});

it('lets accounting add a bank account and validates the IBAN', function () {
    $user = makeMember($this->a, Role::Accounting);
    useAppPanel($this->a, $user);

    Livewire::test(ManageBankAccounts::class)
        ->callAction('create', data: [
            'label' => 'Zweitkonto',
            'iban' => 'CH21 0630 0505 2820 3267 6',
        ])
        ->assertHasActionErrors(['iban']);

    Livewire::test(ManageBankAccounts::class)
        ->callAction('create', data: [
            'label' => 'Zweitkonto',
            'iban' => 'CH08 0630 0508 1155 9749 1',
        ])
        ->assertHasNoActionErrors();

    expect(BankAccount::where('label', 'Zweitkonto')->first())
        ->tenant_id->toBe($this->a->id)
        ->iban->toBe('CH0806300508115597491');
});

it('does not let sales change bank accounts', function () {
    $user = makeMember($this->a, Role::Sales);
    useAppPanel($this->a, $user);

    Livewire::test(ManageBankAccounts::class)
        ->assertActionHidden('create');
});
