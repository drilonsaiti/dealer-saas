<?php

namespace App\Domain\Integrations\Support;

/**
 * A vehicle as it comes from a portal's stock (normalised by the channel's mapper).
 */
final readonly class PortalVehicle
{
    /**
     * @param  list<string>  $photoUrls
     */
    public function __construct(
        public string $externalId,
        public string $make,
        public string $model,
        public ?string $variant = null,
        public ?string $vin = null,
        public ?string $firstRegistration = null, // Y-m-d
        public ?int $mileage = null,
        public ?int $priceRp = null,
        public ?string $fuel = null,
        public ?string $transmission = null,
        public ?int $powerKw = null,
        public ?string $color = null,
        public ?string $description = null,
        public array $photoUrls = [],
        public ?string $url = null,
    ) {}
}
