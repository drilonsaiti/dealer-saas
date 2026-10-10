<?php

namespace App\Domain\Integrations\Channels\AutoScout24;

use App\Domain\Documents\Models\Document;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Support\PortalVehicle;
use App\Domain\Listings\Enums\Availability;
use App\Domain\Listings\Models\Listing;
use App\Domain\Vehicles\Enums\BodyType;
use App\Domain\Vehicles\Enums\FuelType;
use App\Domain\Vehicles\Enums\Transmission;
use App\Support\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\URL;

/**
 * Translates between our listing and the AutoScout24 CH vehicle format, both ways.
 *
 * The field names follow the usual AutoScout24 vocabulary and must be checked against the
 * DMS API documentation before going live (see config/integrations.php). Nothing outside this
 * class and AutoScout24Channel knows them.
 */
class AutoScout24Mapper
{
    /**
     * The vehicle as AutoScout24 expects it. Deterministic (photo URLs do not expire), so the
     * hash tells whether anything changed since the last sync.
     *
     * @return array<string, mixed>
     */
    public function toPortal(IntegrationAccount $account, Listing $listing): array
    {
        $cycle = $listing->stockCycle;
        $vehicle = $cycle->vehicle;
        $maxPhotos = (int) config('integrations.autoscout24.max_photos', 30);

        $texts = fn (string $field): array => array_filter(
            Arr::only($listing->getTranslations($field), ['de', 'fr', 'it', 'en']),
            fn ($v): bool => filled($v),
        );

        return array_filter([
            'externalReference' => $cycle->number ?? $cycle->getKey(),
            'vehicleCategory' => 'car',
            'condition' => 'used',
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'version' => $vehicle->variant,
            'title' => $texts('title'),
            'description' => $texts('description'),
            'highlights' => array_values($listing->highlights ?? []),
            'vin' => $account->setting('send_vin', false) ? $vehicle->vin : null,
            'firstRegistrationDate' => $vehicle->first_registration_on?->format('Y-m'),
            'mileage' => $cycle->mileage_in,
            'price' => Money::decimal($listing->price_rp),
            'currency' => 'CHF',
            'priceVisible' => $listing->show_price,
            'fuelType' => $vehicle->fuel !== null ? self::FUEL[$vehicle->fuel->value] : null,
            'transmissionType' => $vehicle->transmission !== null ? self::TRANSMISSION[$vehicle->transmission->value] : null,
            'bodyType' => $vehicle->body_type !== null ? self::BODY[$vehicle->body_type->value] : null,
            'powerKw' => $vehicle->power_kw,
            'cubicCapacity' => $vehicle->displacement_cc,
            'doors' => $vehicle->doors,
            'seats' => $vehicle->seats,
            'exteriorColor' => $vehicle->color_exterior,
            'interiorColor' => $vehicle->color_interior,
            'lastInspectionDate' => $vehicle->mfk_last_on?->format('Y-m-d'),
            'equipment' => $vehicle->equipment ?? [],
            'reserved' => $listing->availability() === Availability::Reserved,
            'images' => $listing->photos()->take($maxPhotos)->values()->map(fn (Document $photo, int $i): array => [
                'url' => URL::signedRoute('api.v1.photo', ['tenant' => $listing->tenant_id, 'listing' => $listing->getKey(), 'document' => $photo->getKey()]),
                'position' => $i + 1,
            ])->all(),
        ], fn ($v): bool => $v !== null && $v !== [] && $v !== '');
    }

    /**
     * One vehicle from the portal's stock, as far as it is filled in.
     *
     * @param  array<string, mixed>  $data
     */
    public function fromPortal(array $data): ?PortalVehicle
    {
        $id = Arr::get($data, 'id') ?? Arr::get($data, 'listingId');
        $make = Arr::get($data, 'make.name', Arr::get($data, 'make'));
        $model = Arr::get($data, 'model.name', Arr::get($data, 'model'));

        if (! is_scalar($id) || ! is_string($make) || ! is_string($model)) {
            return null;
        }

        $price = Arr::get($data, 'price.amount', Arr::get($data, 'price'));
        $registration = Arr::get($data, 'firstRegistrationDate');
        $description = Arr::get($data, 'description');

        if (is_array($description)) {
            $description = $description['de'] ?? reset($description) ?: null;
        }

        return new PortalVehicle(
            externalId: (string) $id,
            make: $make,
            model: $model,
            variant: self::string(Arr::get($data, 'version')),
            vin: self::string(Arr::get($data, 'vin')),
            firstRegistration: is_string($registration) && preg_match('/^(\d{4})-(\d{2})(?:-(\d{2}))?/', $registration, $m) === 1
                ? sprintf('%s-%s-%s', $m[1], $m[2], $m[3] ?? '01')
                : null,
            mileage: is_numeric(Arr::get($data, 'mileage')) ? (int) Arr::get($data, 'mileage') : null,
            priceRp: is_numeric($price) ? (int) round(((float) $price) * 100) : null,
            fuel: ($f = array_search(Arr::get($data, 'fuelType'), self::FUEL, true)) !== false ? (string) $f : null,
            transmission: ($t = array_search(Arr::get($data, 'transmissionType'), self::TRANSMISSION, true)) !== false ? (string) $t : null,
            powerKw: is_numeric(Arr::get($data, 'powerKw')) ? (int) Arr::get($data, 'powerKw') : null,
            color: self::string(Arr::get($data, 'exteriorColor')),
            description: is_string($description) ? $description : null,
            photoUrls: array_values(array_filter(array_map(
                fn ($image): ?string => is_array($image) ? self::string($image['url'] ?? null) : self::string($image),
                (array) Arr::get($data, 'images', []),
            ))),
            url: self::string(Arr::get($data, 'url')),
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @var array<string, string> */
    private const FUEL = [
        FuelType::Petrol->value => 'petrol',
        FuelType::Diesel->value => 'diesel',
        FuelType::Hybrid->value => 'hybrid-petrol',
        FuelType::PlugInHybrid->value => 'plug-in-hybrid',
        FuelType::Electric->value => 'electric',
        FuelType::Gas->value => 'cng',
        FuelType::Hydrogen->value => 'hydrogen',
        FuelType::Other->value => 'other',
    ];

    /** @var array<string, string> */
    private const TRANSMISSION = [
        Transmission::Manual->value => 'manual',
        Transmission::Automatic->value => 'automatic',
    ];

    /** @var array<string, string> */
    private const BODY = [
        BodyType::Sedan->value => 'saloon',
        BodyType::Estate->value => 'estate',
        BodyType::Hatchback->value => 'small-car',
        BodyType::Suv->value => 'suv',
        BodyType::Coupe->value => 'coupe',
        BodyType::Convertible->value => 'cabriolet',
        BodyType::Van->value => 'bus',
        BodyType::Pickup->value => 'pick-up',
        BodyType::Other->value => 'other',
    ];
}
