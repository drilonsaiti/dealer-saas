<?php

use App\Domain\Tenancy\Enums\Role;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

function tenantContext(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * Create a tenant (dealer) the way the platform does: with bypass, outside any tenant.
 */
function makeTenant(array $attributes = []): Tenant
{
    return tenantContext()->bypass(fn () => Tenant::factory()->create($attributes));
}

function makeMember(Tenant $tenant, Role $role = Role::Sales, array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    tenantContext()->run($tenant, fn () => $tenant->addMember($user, $role));

    return $user;
}

/**
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function asTenant(Tenant $tenant, Closure $callback): mixed
{
    return tenantContext()->run($tenant, $callback);
}

/**
 * Prepare Filament + tenant context for Livewire component tests in the dealer app panel.
 */
function useAppPanel(Tenant $tenant, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel('app');
    Filament::setTenant($tenant);
    tenantContext()->set($tenant);
}
