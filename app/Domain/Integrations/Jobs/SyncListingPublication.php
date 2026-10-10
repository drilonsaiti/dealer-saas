<?php

namespace App\Domain\Integrations\Jobs;

use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Models\IntegrationLog;
use App\Domain\Integrations\Support\Channels;
use App\Domain\Integrations\Support\IntegrationException;
use App\Domain\Listings\Enums\Availability;
use App\Domain\Listings\Enums\ListingStatus;
use App\Domain\Listings\Enums\PublicationStatus;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Models\ListingPublication;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings one listing on one portal in line with the vehicle file:
 * published and available/reserved → online (created or updated, skipped if unchanged);
 * withdrawn, sold or cancelled → removed. Errors are logged, shown on the vehicle file and
 * retried with growing pauses when the portal may recover.
 */
class SyncListingPublication implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 30;

    public function __construct(public string $listingId, public string $accountId) {}

    public function uniqueId(): string
    {
        return $this->listingId.':'.$this->accountId;
    }

    public function handle(): void
    {
        $account = IntegrationAccount::query()->find($this->accountId);
        $listing = Listing::query()->with('stockCycle.vehicle')->find($this->listingId);

        if ($account === null || ! $account->is_active || $listing === null || $listing->status === ListingStatus::Draft) {
            return;
        }

        $channel = Channels::for($account->provider);
        $publication = ListingPublication::query()->firstOrCreate(['listing_id' => $listing->getKey(), 'channel' => $account->provider]);
        $online = $this->shouldBeOnline($account, $listing);
        $started = hrtime(true);

        try {
            if (! $online) {
                if ($publication->external_id === null) {
                    if ($publication->status !== PublicationStatus::Removed) {
                        $publication->forceFill(['status' => PublicationStatus::Removed, 'last_error' => null])->save();
                    }

                    return;
                }

                $channel->remove($account, $publication->external_id);
                $publication->forceFill(['status' => PublicationStatus::Removed, 'external_id' => null, 'external_url' => null, 'payload_hash' => null, 'last_synced_at' => now(), 'last_error' => null])->save();
                $this->log($account, $listing, 'remove', 'ok', null, $started);

                return;
            }

            $payload = $channel->payload($account, $listing);
            $hash = hash('sha256', (string) json_encode($payload));

            if ($publication->external_id !== null && $publication->status === PublicationStatus::Published && $publication->payload_hash === $hash) {
                return; // nothing changed since the last sync
            }

            $action = 'update';

            try {
                $result = $publication->external_id !== null
                    ? $channel->update($account, $publication->external_id, $payload)
                    : null;
            } catch (IntegrationException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }

                $result = null; // deleted on the portal in the meantime: create it again
            }

            if ($result === null) {
                $action = 'publish';
                $result = $channel->publish($account, $payload);
            }

            $publication->forceFill([
                'status' => PublicationStatus::Published,
                'external_id' => $result->externalId,
                'external_url' => $result->url ?? $publication->external_url,
                'payload_hash' => $hash,
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();

            $this->log($account, $listing, $action, 'ok', null, $started);
            $account->forceFill(['last_synced_at' => now(), 'last_error' => null, 'status' => 'ok'])->save();
        } catch (IntegrationException $e) {
            $this->recordFailure($account, $listing, $publication, $online ? 'publish' : 'remove', $e->getMessage(), $e->status, $started);

            if ($e->retryable && $this->attempts() < $this->tries) {
                $backoff = (array) config('integrations.retry_backoff', [60, 300, 1800, 7200]);
                $this->release((int) ($backoff[min($this->attempts() - 1, count($backoff) - 1)] ?? 600));
            }
        }
    }

    private function recordFailure(IntegrationAccount $account, Listing $listing, ListingPublication $publication, string $action, string $message, ?int $code, int $started): void
    {
        $publication->forceFill(['status' => PublicationStatus::Failed, 'last_error' => $message])->save();
        $account->forceFill(['last_error' => $message])->save();
        $this->log($account, $listing, $action, 'error', $message, $started, $code);
    }

    private function shouldBeOnline(IntegrationAccount $account, Listing $listing): bool
    {
        return match ($listing->availability()) {
            Availability::Available => true,
            Availability::Reserved => ! $account->setting('remove_when_reserved', false),
            default => false,
        };
    }

    private function log(IntegrationAccount $account, Listing $listing, string $action, string $status, ?string $message, ?int $started, ?int $code = null): void
    {
        IntegrationLog::query()->create([
            'integration_account_id' => $account->getKey(),
            'listing_id' => $listing->getKey(),
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'response_code' => $code,
            'duration_ms' => $started !== null ? (int) ((hrtime(true) - $started) / 1_000_000) : null,
        ]);
    }
}
