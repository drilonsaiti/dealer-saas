<?php

namespace App\Domain\Listings\Listeners;

use App\Domain\Api\Support\Webhooks;
use App\Domain\Integrations\Support\ListingSync;
use App\Domain\Listings\Enums\ListingStatus;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Support\ListingPayload;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Events\StockCycleStatusChanged;

/**
 * A published car changes status in its file: webhooks tell the dealer's other systems
 * (listed, reserved, sold, unlisted) and the portals are synced. The website reads the
 * availability live from the API.
 */
class AnnounceListingChanges
{
    public function __construct(
        private readonly Webhooks $webhooks,
        private readonly ListingSync $sync,
    ) {}

    public function handle(StockCycleStatusChanged $event): void
    {
        $listing = Listing::query()->where('stock_cycle_id', $event->cycle->getKey())->where('status', ListingStatus::Published->value)->first();

        if ($listing === null) {
            return;
        }

        $name = match ($event->to) {
            StockCycleStatus::Listed => 'vehicle.listed',
            StockCycleStatus::Reserved => 'vehicle.reserved',
            StockCycleStatus::Sold => 'vehicle.sold',
            StockCycleStatus::Cancelled => 'vehicle.unlisted',
            StockCycleStatus::ReadyForSale => $event->from === StockCycleStatus::Reserved ? 'vehicle.listed' : null,
            default => null,
        };

        if ($name !== null) {
            $this->webhooks->dispatch($name, ListingPayload::event($listing->setRelation('stockCycle', $event->cycle)));
        }

        // Portals: reserved / sold / back on sale (the sync reads the current state itself).
        $this->sync->listing($listing);
    }
}
