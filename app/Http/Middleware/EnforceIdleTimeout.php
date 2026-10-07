<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs the user out after the dealer's configured inactivity time (default 30 minutes).
 * Runs as tenant middleware, after ApplyTenantContext.
 */
class EnforceIdleTimeout
{
    public const SESSION_KEY = 'dealer.last_activity_at';

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->tenant();

        if ($tenant !== null && $request->hasSession()) {
            $last = $request->session()->get(self::SESSION_KEY);
            $limit = $tenant->idleTimeoutMinutes() * 60;

            if (is_int($last) && (time() - $last) > $limit) {
                Filament::auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->to(Filament::getLoginUrl() ?? '/');
            }

            $request->session()->put(self::SESSION_KEY, time());
        }

        return $next($request);
    }
}
