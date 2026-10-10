<?php

namespace App\Domain\Integrations\Actions;

use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Support\ListingSync;
use App\Domain\Integrations\Support\Providers;
use Illuminate\Support\Str;

/**
 * Saves the dealer's account at a portal. Secrets left empty keep the stored value (they are
 * never shown again). Switching it on sends all online listings; switching it off leaves the
 * portal as it is (the dealer may still want the cars there).
 */
class SaveIntegrationAccount
{
    public function __construct(private readonly ListingSync $sync) {}

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $settings
     */
    public function __invoke(string $provider, array $credentials, array $settings = [], bool $active = false): IntegrationAccount
    {
        Providers::definition($provider); // unknown provider → exception

        $account = IntegrationAccount::query()->firstOrNew(['provider' => $provider]);
        $wasActive = $account->exists && $account->is_active;
        $stored = $account->credentials ?? [];

        foreach ($credentials as $key => $value) {
            if (filled($value)) {
                $stored[$key] = trim((string) $value);
            }
        }

        $changedCredentials = $stored !== ($account->credentials ?? []);

        $settings = [...($account->settings ?? []), ...$settings];

        if ($provider === IntegrationAccount::WHATSAPP && blank($settings['verify_token'] ?? null)) {
            $settings['verify_token'] = Str::random(32); // entered in the Meta app with the webhook address
        }

        $account->fill([
            'credentials' => $stored,
            'settings' => $settings,
            'is_active' => $active,
        ]);

        if ($changedCredentials) {
            $account->forceFill(['status' => 'unchecked', 'last_error' => null]);
        }

        $account->save();

        if ($active && ! $wasActive && Providers::kind($provider) === Providers::LISTING) {
            $this->sync->all($account);
        }

        return $account;
    }
}
