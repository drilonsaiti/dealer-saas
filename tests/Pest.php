<?php

use App\Domain\Purchasing\Actions\InstallDefaultCostCategories;
use App\Domain\Settings\Enums\NumberSequenceKey;
use App\Domain\Settings\Models\NumberSequence;
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

/**
 * A dealer with the default number ranges and cost categories, as CreateTenant sets them up.
 */
function makeDealer(array $attributes = []): Tenant
{
    $tenant = makeTenant($attributes);

    asTenant($tenant, function () {
        foreach (NumberSequenceKey::cases() as $key) {
            NumberSequence::create([
                'key' => $key,
                'pattern' => $key->defaultPattern(),
                'reset_yearly' => $key->resetsYearlyByDefault(),
            ]);
        }

        app(InstallDefaultCostCategories::class)();
    });

    return $tenant;
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
