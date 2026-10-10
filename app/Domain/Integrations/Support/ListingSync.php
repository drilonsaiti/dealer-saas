<?php

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\Jobs\SyncListingPublication;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Listings\Enums\ListingStatus;
use App\Domain\Listings\Models\Listing;

/**
 * The one place that tells the portals about a change of a listing: published, edited,
 * withdrawn, reserved, sold. Each active portal account gets a queued sync after the commit;
 * the sync itself decides whether to publish, update or remove (and skips if nothing changed).
 */
class ListingSync
{
    public function listing(Listing $listing): int
    {
        // A draft was never sent; its portal entry (e.g. imported) is left alone.
        if ($listing->status === ListingStatus::Draft) {
            return 0;
        }

        $accounts = IntegrationAccount::query()->active()->pluck('id');

        foreach ($accounts as $accountId) {
            SyncListingPublication::dispatch($listing->getKey(), $accountId)->afterCommit();
        }

        return $accounts->count();
    }

    /**
     * All listings of the dealer that may be on a portal (catch-up, "sync all").
     */
    public function all(?IntegrationAccount $account = null): int
    {
        $accounts = $account !== null ? collect([$account->getKey()]) : IntegrationAccount::query()->active()->pluck('id');
        $count = 0;

        Listing::query()->where('status', '!=', ListingStatus::Draft->value)
            ->where(fn ($q) => $q->where('status', ListingStatus::Published->value)
                ->orWhereHas('publications', fn ($p) => $p->whereNotNull('external_id')))
            ->pluck('id')
            ->each(function (string $listingId) use ($accounts, &$count): void {
                foreach ($accounts as $accountId) {
                    SyncListingPublication::dispatch($listingId, $accountId)->afterCommit();
                    $count++;
                }
            });

        return $count;
    }
}
