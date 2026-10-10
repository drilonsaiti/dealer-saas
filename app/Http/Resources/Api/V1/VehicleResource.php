<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Documents\Models\Document;
use App\Domain\Listings\Models\Listing;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\URL;

/**
 * A published car in the public API (JSON:API "vehicles"), in the requested language.
 * Photos are signed URLs that work without the token (for <img> on the dealer's website).
 *
 * @property Listing $resource
 */
class VehicleResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'vehicles';
    }

    public function toId(Request $request): string
    {
        return $this->resource->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        $listing = $this->resource;
        $cycle = $listing->stockCycle;
        $vehicle = $cycle->vehicle;
        $locale = (string) $request->attributes->get('api_locale', 'de');

        return [
            'title' => $listing->getTranslation('title', $locale),
            'description' => $listing->getTranslation('description', $locale) ?: null,
            'highlights' => array_values($listing->highlights ?? []),
            'availability' => $listing->availability()->value,
            'price' => $listing->show_price ? ['amount' => Money::decimal($listing->price_rp), 'currency' => 'CHF'] : null,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'variant' => $vehicle->variant,
            'body_type' => $vehicle->body_type?->value,
            'fuel' => $vehicle->fuel?->value,
            'transmission' => $vehicle->transmission?->value,
            'drive' => $vehicle->drive?->value,
            'first_registration' => $vehicle->first_registration_on?->format('Y-m'),
            'mileage_km' => $cycle->mileage_in,
            'power_kw' => $vehicle->power_kw,
            'power_hp' => $vehicle->power_kw === null ? null : (int) round($vehicle->power_kw * 1.35962),
            'doors' => $vehicle->doors,
            'seats' => $vehicle->seats,
            'color_exterior' => $vehicle->color_exterior,
            'color_interior' => $vehicle->color_interior,
            'equipment' => $vehicle->equipment ?? [],
            'mfk_due' => $vehicle->mfk_due_on?->format('Y-m'),
            'photos' => $listing->photos()->map(fn (Document $photo, int $i): array => [
                'url' => URL::temporarySignedRoute('api.v1.photo', now()->addDays(30), ['tenant' => $listing->tenant_id, 'listing' => $listing->getKey(), 'document' => $photo->getKey()]),
                'cover' => $i === 0,
            ])->all(),
            'published_at' => $listing->published_at?->toIso8601String(),
            'updated_at' => $listing->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function toLinks(Request $request): array
    {
        return ['self' => route('api.v1.vehicles.show', $this->resource->getKey())];
    }
}
