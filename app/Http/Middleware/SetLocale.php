<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the UI language: the user's own setting, else the dealer's language (once the dealer
 * is known: this also runs after the tenant is applied), else the browser's, else APP_LOCALE.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = config('dealer.locales');

        $user = $request->user();

        $tenantLocale = app(TenantContext::class)->tenant()?->default_locale;

        $locale = match (true) {
            $user instanceof User && in_array($user->locale, $supported, true) => $user->locale,
            in_array($tenantLocale, $supported, true) => $tenantLocale,
            default => $request->getPreferredLanguage($supported) ?? config('app.locale'),
        };

        app()->setLocale($locale);

        return $next($request);
    }
}
