<?php

namespace App\Domain\VehicleData\Providers\AutoIDat;

use App\Domain\VehicleData\Support\VehicleData;
use App\Domain\VehicleData\Support\VehicleValuation;
use App\Domain\Vehicles\Enums\BodyType;
use App\Domain\Vehicles\Enums\DriveType;
use App\Domain\Vehicles\Enums\FuelType;
use App\Domain\Vehicles\Enums\Transmission;
use Illuminate\Support\Arr;

/**
 * Auto-i-DAT answers → our vehicle data. Field names and code lists are an assumption until
 * checked against the licensed documentation.
 */
class AutoIDatMapper
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function vehicle(array $data): ?VehicleData
    {
        $id = Arr::get($data, 'id') ?? Arr::get($data, 'vehicleId');
        $make = Arr::get($data, 'make.name', Arr::get($data, 'make'));
        $model = Arr::get($data, 'model.name', Arr::get($data, 'model'));

        if (! is_scalar($id) || ! is_string($make) || ! is_string($model)) {
            return null;
        }

        $equipment = fn (string $key): array => array_values(array_filter(array_map(
            fn ($item): ?string => is_array($item) ? self::string($item['name'] ?? $item['text'] ?? null) : self::string($item),
            (array) Arr::get($data, $key, []),
        )));

        $price = Arr::get($data, 'newPrice', Arr::get($data, 'listPrice'));

        return new VehicleData(
            externalId: (string) $id,
            make: $make,
            model: $model,
            variant: self::string(Arr::get($data, 'version', Arr::get($data, 'variant'))),
            typeApproval: self::string(Arr::get($data, 'typeApproval')),
            bodyType: self::code(Arr::get($data, 'bodyType'), self::BODY),
            fuel: self::code(Arr::get($data, 'fuelType'), self::FUEL),
            transmission: self::code(Arr::get($data, 'transmissionType'), self::TRANSMISSION),
            drive: self::code(Arr::get($data, 'driveType'), self::DRIVE),
            powerKw: self::int(Arr::get($data, 'powerKw')),
            displacementCc: self::int(Arr::get($data, 'cubicCapacity')),
            doors: self::int(Arr::get($data, 'doors')),
            seats: self::int(Arr::get($data, 'seats')),
            curbWeightKg: self::int(Arr::get($data, 'curbWeight')),
            totalWeightKg: self::int(Arr::get($data, 'totalWeight')),
            standardEquipment: $equipment('standardEquipment'),
            optionalEquipment: $equipment('optionalEquipment'),
            newPriceRp: is_numeric($price) ? (int) round(((float) $price) * 100) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function valuation(array $data): VehicleValuation
    {
        $amount = fn (string $key): ?int => is_numeric($v = Arr::get($data, $key)) ? (int) round(((float) $v) * 100) : null;

        return new VehicleValuation(
            retailRp: $amount('retailPrice') ?? $amount('marketValue'),
            tradeInRp: $amount('tradeInPrice') ?? $amount('purchaseValue'),
            reference: self::string(Arr::get($data, 'valuationId', Arr::get($data, 'reference'))),
        );
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function code(mixed $value, array $map): ?string
    {
        return is_string($value) ? ($map[strtolower($value)] ?? null) : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @var array<string, string> */
    private const FUEL = [
        'petrol' => FuelType::Petrol->value, 'benzin' => FuelType::Petrol->value,
        'diesel' => FuelType::Diesel->value,
        'hybrid' => FuelType::Hybrid->value, 'hybrid-petrol' => FuelType::Hybrid->value,
        'plug-in-hybrid' => FuelType::PlugInHybrid->value, 'phev' => FuelType::PlugInHybrid->value,
        'electric' => FuelType::Electric->value, 'bev' => FuelType::Electric->value,
        'cng' => FuelType::Gas->value, 'lpg' => FuelType::Gas->value,
        'hydrogen' => FuelType::Hydrogen->value,
    ];

    /** @var array<string, string> */
    private const TRANSMISSION = [
        'manual' => Transmission::Manual->value,
        'automatic' => Transmission::Automatic->value, 'semi-automatic' => Transmission::Automatic->value,
    ];

    /** @var array<string, string> */
    private const DRIVE = [
        'front' => DriveType::Front->value, 'fwd' => DriveType::Front->value,
        'rear' => DriveType::Rear->value, 'rwd' => DriveType::Rear->value,
        'all' => DriveType::AllWheel->value, '4x4' => DriveType::AllWheel->value, 'awd' => DriveType::AllWheel->value,
    ];

    /** @var array<string, string> */
    private const BODY = [
        'saloon' => BodyType::Sedan->value, 'sedan' => BodyType::Sedan->value,
        'estate' => BodyType::Estate->value, 'station-wagon' => BodyType::Estate->value,
        'hatchback' => BodyType::Hatchback->value, 'small-car' => BodyType::Hatchback->value,
        'suv' => BodyType::Suv->value, 'off-road' => BodyType::Suv->value,
        'coupe' => BodyType::Coupe->value,
        'cabriolet' => BodyType::Convertible->value, 'convertible' => BodyType::Convertible->value,
        'van' => BodyType::Van->value, 'bus' => BodyType::Van->value,
        'pick-up' => BodyType::Pickup->value, 'pickup' => BodyType::Pickup->value,
    ];
}
