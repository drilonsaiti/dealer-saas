<?php

namespace App\Domain\Listings\Actions;

use App\Domain\Api\Support\Webhooks;
use App\Domain\Integrations\Support\ListingSync;
use App\Domain\Listings\Enums\ListingStatus;
use App\Domain\Listings\Enums\PublicationStatus;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Models\ListingPublication;
use App\Domain\Listings\Support\ListingPayload;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Puts the advert online on the dealer's own website (public API) and moves the file to
 * "listed". Withdrawing takes it offline and back to "ready for sale". Portals (AutoScout24)
 * follow the same listing in their connector.
 */
class PublishListing
{
    public function __construct(
        private readonly TransitionStockCycle $transition,
        private readonly Webhooks $webhooks,
        private readonly ListingSync $sync,
    ) {}

    public function __invoke(Listing $listing): Listing
    {
        $cycle = $listing->stockCycle;
        $problems = [];

        if (! in_array($cycle->status, [StockCycleStatus::ReadyForSale, StockCycleStatus::Listed, StockCycleStatus::Reserved], true)) {
            $problems[] = __('Only a vehicle that is ready for sale can be published (now: :status).', ['status' => $cycle->status->getLabel()]);
        }

        if (($listing->photo_document_ids ?? []) === []) {
            $problems[] = __('Choose at least one photo.');
        }

        if ($listing->price_rp <= 0) {
            $problems[] = __('Enter the price.');
        }

        if ($problems !== []) {
            throw BusinessRuleException::because($problems);
        }

        return DB::transaction(function () use ($listing, $cycle): Listing {
            $listing->forceFill(['status' => ListingStatus::Published, 'published_at' => $listing->published_at ?? now(), 'withdrawn_at' => null])->save();

            if ($cycle->list_price_rp !== $listing->price_rp) {
                $cycle->forceFill(['list_price_rp' => $listing->price_rp])->save();
            }

            $publication = ListingPublication::query()->firstOrCreate(['listing_id' => $listing->getKey(), 'channel' => ListingPublication::WEBSITE]);
            $publication->forceFill(['status' => PublicationStatus::Published, 'last_synced_at' => now(), 'last_error' => null])->save();

            if ($cycle->status === StockCycleStatus::ReadyForSale) {
                ($this->transition)($cycle, StockCycleStatus::Listed); // fires vehicle.listed
            } else {
                $this->webhooks->dispatch('vehicle.listed', ListingPayload::event($listing->refresh()));
            }

            $this->sync->listing($listing->refresh());

            return $listing;
        });
    }

    public function withdraw(Listing $listing): Listing
    {
        if ($listing->status !== ListingStatus::Published) {
            throw new BusinessRuleException(__('The listing is not online.'));
        }

        return DB::transaction(function () use ($listing): Listing {
            $listing->forceFill(['status' => ListingStatus::Withdrawn, 'withdrawn_at' => now()])->save();
            // The website follows at once; portals are taken offline by their sync (it needs the external id).
            $listing->publications()->where('channel', ListingPublication::WEBSITE)->update(['status' => PublicationStatus::Removed->value, 'last_synced_at' => now()]);
            $cycle = $listing->stockCycle;

            if ($cycle->status === StockCycleStatus::Listed) {
                ($this->transition)($cycle, StockCycleStatus::ReadyForSale, reason: __('Listing withdrawn'));
            }

            $this->webhooks->dispatch('vehicle.unlisted', ListingPayload::event($listing->refresh()));
            $this->sync->listing($listing);

            return $listing;
        });
    }
}
