<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Filament decides whether the MFA middleware is attached when routes are registered (no user yet),
 * so the panel always registers it and this middleware decides per user:
 * administrators, accounting, platform admins, and everyone in dealers that demand it.
 */
class RequireMultiFactorAuthenticationForRole
{
    public function __construct(private readonly EnsureMultiFactorAuthenticationIsEnabled $ensureEnabled) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->requiresMultiFactorAuthentication()) {
            return $next($request);
        }

        return $this->ensureEnabled->handle($request, $next);
    }
}
