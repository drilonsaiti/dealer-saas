<?php

namespace App\Domain\Listings\Support;

use App\Domain\Listings\Models\Listing;
use App\Support\Money;

/**
 * The short form of a listing in webhook events (the full data comes from the API).
 */
final class ListingPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function event(Listing $listing): array
    {
        $vehicle = $listing->stockCycle->vehicle;

        return [
            'vehicle_id' => $listing->getKey(),
            'file_number' => $listing->stockCycle->number,
            'title' => $listing->getTranslation('title', 'de'),
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'price' => $listing->show_price ? Money::decimal($listing->price_rp) : null,
            'availability' => $listing->availability()->value,
        ];
    }
}
