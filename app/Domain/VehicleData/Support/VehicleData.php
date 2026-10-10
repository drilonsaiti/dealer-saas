<?php

namespace App\Domain\VehicleData\Support;

/**
 * One vehicle variant as the data provider describes it (our enum values already mapped).
 */
final readonly class VehicleData
{
    /**
     * @param  list<string>  $standardEquipment
     * @param  list<string>  $optionalEquipment
     */
    public function __construct(
        public string $externalId,
        public string $make,
        public string $model,
        public ?string $variant = null,
        public ?string $typeApproval = null,
        public ?string $bodyType = null,
        public ?string $fuel = null,
        public ?string $transmission = null,
        public ?string $drive = null,
        public ?int $powerKw = null,
        public ?int $displacementCc = null,
        public ?int $doors = null,
        public ?int $seats = null,
        public ?int $curbWeightKg = null,
        public ?int $totalWeightKg = null,
        public array $standardEquipment = [],
        public array $optionalEquipment = [],
        public ?int $newPriceRp = null,
    ) {}

    public function label(): string
    {
        return trim("{$this->make} {$this->model} ".($this->variant ?? '')).($this->powerKw !== null ? " · {$this->powerKw} kW" : '');
    }

    /**
     * The vehicle attributes this data can fill.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'make' => $this->make,
            'model' => $this->model,
            'variant' => $this->variant,
            'type_approval' => $this->typeApproval,
            'body_type' => $this->bodyType,
            'fuel' => $this->fuel,
            'transmission' => $this->transmission,
            'drive' => $this->drive,
            'power_kw' => $this->powerKw,
            'displacement_cc' => $this->displacementCc,
            'doors' => $this->doors,
            'seats' => $this->seats,
            'curb_weight_kg' => $this->curbWeightKg,
            'total_weight_kg' => $this->totalWeightKg,
        ];
    }
}
