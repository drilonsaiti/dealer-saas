<?php

namespace App\Domain\Api\Actions;

use App\Domain\Api\Models\ApiToken;
use App\Support\BusinessRuleException;
use Illuminate\Support\Str;

/**
 * Creates an API token for the dealer's website. The plain token is returned once and never
 * stored (only its SHA-256).
 */
class IssueApiToken
{
    /**
     * @param  list<string>  $abilities
     * @return array{0: ApiToken, 1: string}
     */
    public function __invoke(string $name, array $abilities, ?string $expiresAt = null): array
    {
        $abilities = array_values(array_intersect($abilities, ApiToken::ABILITIES));

        if (trim($name) === '' || $abilities === []) {
            throw new BusinessRuleException(__('Give the token a name and at least one permission.'));
        }

        $plain = 'dsk_'.Str::random(40);
        $token = ApiToken::create([
            'name' => trim($name),
            'token_prefix' => substr($plain, 0, 10),
            'token_hash' => hash('sha256', $plain),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plain];
    }

    public function revoke(ApiToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
    }
}
