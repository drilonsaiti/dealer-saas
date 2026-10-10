<?php

namespace App\Http\Middleware;

use App\Domain\Api\Models\ApiToken;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public API: "Authorization: Bearer dsk_…". The token decides the dealer; the request then
 * runs as that dealer (RLS), so it can only ever see that dealer's data. Optional ability
 * parameter, e.g. "api.token:listings:read".
 */
class AuthenticateApiToken
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $plain = (string) $request->bearerToken();

        if ($plain === '' || ! str_starts_with($plain, 'dsk_')) {
            return $this->error(401, 'Missing or malformed API token.');
        }

        /** @var ApiToken|null $token */
        $token = $this->context->bypass(fn () => ApiToken::query()->withoutGlobalScopes()->with('tenant')->where('token_hash', hash('sha256', $plain))->first());

        if ($token === null || ! $token->isUsable() || $token->tenant->status !== Tenant::STATUS_ACTIVE) {
            return $this->error(401, 'Invalid or revoked API token.');
        }

        if ($ability !== null && ! $token->can($ability)) {
            return $this->error(403, "This token may not use \"{$ability}\".");
        }

        $this->context->set($token->tenant);
        app()->terminating(fn () => $this->context->clear());
        $request->attributes->set('api_token_id', $token->getKey());
        $request->attributes->set('api_token', $token);

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    private function error(int $status, string $detail): Response
    {
        return response()->json(['errors' => [['status' => (string) $status, 'title' => $status === 401 ? 'Unauthorized' : 'Forbidden', 'detail' => $detail]]], $status, ['Content-Type' => 'application/vnd.api+json']);
    }
}
