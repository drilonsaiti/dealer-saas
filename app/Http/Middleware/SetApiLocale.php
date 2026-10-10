<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Language of the API texts: ?lang=fr, else Accept-Language, else the dealer's default.
 */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locales = (array) config('dealer.locales');
        $wanted = $request->query('lang') ?? $request->getPreferredLanguage($locales);
        $tenant = app(TenantContext::class)->tenant();
        $locale = in_array($wanted, $locales, true) ? $wanted : ($tenant === null ? 'de' : (string) $tenant->default_locale);

        $request->attributes->set('api_locale', $locale);
        app()->setLocale($locale);

        return $next($request);
    }
}
