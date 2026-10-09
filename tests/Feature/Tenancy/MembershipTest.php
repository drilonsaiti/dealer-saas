<?php

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Actions\InviteMember;
use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

it('creates a dealer with its first administrator and sends an invitation', function () {
    Notification::fake();

    $tenant = app(CreateTenant::class)(['name' => 'Aziri Automobile GmbH', 'city' => 'Bern'], 'Kontakt@Example.ch', 'Kastriot Aziri');

    $admin = User::where('email', 'kontakt@example.ch')->sole();

    expect($tenant->slug)->toBe('aziri-automobile-gmbh')
        ->and($admin->roleIn($tenant))->toBe(Role::Administrator);

    Notification::assertSentTo($admin, ResetPassword::class, fn (ResetPassword $n) => str_contains((string) $n->url, '/app/password-reset/reset'));
});

it('lets one person work for several dealers with different roles', function () {
    $a = makeTenant();
    $b = makeTenant();
    $user = makeMember($a, Role::Administrator);
    asTenant($b, fn () => app(InviteMember::class)($b, $user->email, null, Role::ReadOnly, sendInvitation: false));

    expect($user->roleIn($a))->toBe(Role::Administrator)
        ->and($user->roleIn($b))->toBe(Role::ReadOnly);

    asTenant($a, fn () => expect($user->hasPermission(Permission::MembersManage))->toBeTrue());
    $user->forgetRoleCache();
    asTenant($b, fn () => expect($user->hasPermission(Permission::MembersManage))->toBeFalse());
});

it('never leaves a dealer without an active administrator', function () {
    $tenant = makeTenant();
    $admin = makeMember($tenant, Role::Administrator);

    $membership = TenantMembership::where('user_id', $admin->id)->sole();

    expect(fn () => asTenant($tenant, fn () => $membership->update(['role' => Role::Sales])))->toThrow(ValidationException::class)
        ->and(fn () => asTenant($tenant, fn () => $membership->delete()))->toThrow(ValidationException::class);

    makeMember($tenant, Role::Administrator);

    asTenant($tenant, fn () => $membership->update(['role' => Role::Sales]));

    expect($membership->fresh()->role)->toBe(Role::Sales);
});

it('maps permissions to roles', function () {
    expect(Role::Administrator->allows(Permission::MembersManage))->toBeTrue()
        ->and(Role::Accounting->allows(Permission::BankAccountsManage))->toBeTrue()
        ->and(Role::Accounting->allows(Permission::MembersManage))->toBeFalse()
        ->and(Role::Sales->allows(Permission::BankAccountsManage))->toBeFalse()
        ->and(Role::ReadOnly->permissions())->toBe([Permission::VehiclesView, Permission::PartiesView, Permission::ReportsView, Permission::DocumentsView, Permission::InvoicesView]); // look, never change
});
