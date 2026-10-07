<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform panel only: platform administrators see all tenants' data.
 * Registered after Filament's Authenticate middleware, so it only runs for signed-in users.
 */
class EnablePlatformBypass
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->is_platform_admin, 403);

        $this->context->enableBypass();

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
