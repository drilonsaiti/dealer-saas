<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Enums\Role;
use App\Http\Middleware\ApplyTenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

beforeEach(function () {
    $this->tenant = makeTenant(['name' => 'Garage A', 'slug' => 'garage-a']);
});

/*
 * Livewire runs persistent middleware to completion before the component action, so the tenant
 * must still be set after the middleware has returned (otherwise every modal/save is a 403).
 */
it('keeps the tenant context until the request terminates', function () {
    $user = makeMember($this->tenant, Role::Administrator);
    $this->actingAs($user);
    Filament::setCurrentPanel('app');
    Filament::setTenant($this->tenant);

    $request = Request::create('/app/garage-a/number-sequences');
    $request->setUserResolver(fn () => $user);

    app(ApplyTenantContext::class)->handle($request, fn () => new Response('ok'));

    expect(tenantContext()->id())->toBe($this->tenant->id)
        ->and($user->fresh()->roleIn($this->tenant))->toBe(Role::Administrator);

    app()->terminate();

    expect(tenantContext()->hasTenant())->toBeFalse();
});

it('audits changes to the company profile', function () {
    $this->actingAs(makeMember($this->tenant, Role::Administrator));

    // Loaded from the database, as Filament does, so strict mode is active for missing attributes.
    asTenant($this->tenant, fn () => $this->tenant->fresh()->update(['phone' => '031 123 45 67']));

    $log = tenantContext()->bypass(fn () => AuditLog::where('auditable_id', $this->tenant->id)->where('event', 'updated')->sole());

    expect($log->tenant_id)->toBe($this->tenant->id)
        ->and($log->new_values)->toBe(['phone' => '031 123 45 67']);
});

it('opens the personal profile page outside any dealer', function () {
    $this->actingAs(makeMember($this->tenant, Role::Sales))
        ->get('/app/profile')
        ->assertOk();
});
