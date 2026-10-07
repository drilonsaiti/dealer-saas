<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after Filament has resolved the tenant from the URL (and checked access).
 * Puts the tenant into the TenantContext, which also sets the PostgreSQL RLS setting.
 */
class ApplyTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant || ! $tenant->isActive()) {
            abort(404);
        }

        $this->context->set($tenant);

        $user = $request->user();

        if ($user instanceof User && $user->last_tenant_id !== $tenant->getKey()) {
            $user->forceFill(['last_tenant_id' => $tenant->getKey()])->saveQuietly();
        }

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
