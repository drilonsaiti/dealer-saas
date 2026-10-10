<?php

namespace App\Domain\Integrations\Actions;

use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Models\IntegrationLog;
use App\Domain\Integrations\Support\IntegrationException;
use App\Domain\Integrations\Support\Providers;

/**
 * "Test connection": logs in with the dealer's credentials and reads one page of stock.
 * Returns null when it works, otherwise the reason (also kept on the account and in the log).
 */
class TestIntegration
{
    public function __invoke(IntegrationAccount $account): ?string
    {
        $started = hrtime(true);

        try {
            Providers::make($account->provider)->test($account);
            $error = null;
            $code = null;
        } catch (IntegrationException $e) {
            $error = $e->getMessage();
            $code = $e->status;
        }

        $account->forceFill(['status' => $error === null ? 'ok' : 'error', 'last_checked_at' => now(), 'last_error' => $error])->save();

        IntegrationLog::query()->create([
            'integration_account_id' => $account->getKey(),
            'action' => 'test',
            'status' => $error === null ? 'ok' : 'error',
            'message' => $error,
            'response_code' => $code,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        return $error;
    }
}
