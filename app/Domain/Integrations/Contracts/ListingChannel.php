<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Support\ChannelResult;
use App\Domain\Integrations\Support\PortalVehicle;
use App\Domain\Listings\Models\Listing;

/**
 * A portal the dealer's listings are published on. Each implementation is an adapter: the
 * core never depends on one being available, and failures are retried and logged.
 */
interface ListingChannel extends Integration
{
    /**
     * What would be sent for this listing (its hash tells whether a sync is needed).
     *
     * @return array<string, mixed>
     */
    public function payload(IntegrationAccount $account, Listing $listing): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(IntegrationAccount $account, array $payload): ChannelResult;

    /**
     * Throws IntegrationException with status 404 when the portal no longer knows the id.
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(IntegrationAccount $account, string $externalId, array $payload): ChannelResult;

    /** Takes the vehicle offline; an id the portal no longer knows counts as removed. */
    public function remove(IntegrationAccount $account, string $externalId): void;

    /**
     * The dealer's listings already on the portal (for a first import).
     *
     * @return iterable<PortalVehicle>
     */
    public function stock(IntegrationAccount $account): iterable;
}
