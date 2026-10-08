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

        // Cleared when the request ends, not when this middleware returns: Livewire replays
        // persistent middleware before it runs the component (a save), and clearing here would
        // drop the bypass in time for the policies to answer 403.
        app()->terminating(fn () => $this->context->clear());

        return $next($request);
    }
}
