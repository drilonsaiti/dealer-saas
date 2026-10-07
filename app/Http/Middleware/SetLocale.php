<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the UI language: the user's own setting when signed in,
 * otherwise the best match from the browser, otherwise the app default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = config('dealer.locales');

        $user = $request->user();

        $locale = $user instanceof User && in_array($user->locale, $supported, true)
            ? $user->locale
            : ($request->getPreferredLanguage($supported) ?? config('app.locale'));

        app()->setLocale($locale);

        return $next($request);
    }
}
