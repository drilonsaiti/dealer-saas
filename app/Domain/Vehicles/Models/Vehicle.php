<?php

namespace App\Domain\Vehicles\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Enums\BodyType;
use App\Domain\Vehicles\Enums\DriveType;
use App\Domain\Vehicles\Enums\FuelType;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Enums\Transmission;
use App\Domain\Vehicles\Enums\VehicleType;
use App\Domain\Vehicles\Support\Stammnummer;
use App\Domain\Vehicles\Support\Vin;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * The physical car. Unique per dealer by Stammnummer (when known); can pass through
 * the dealership several times, each time as its own StockCycle.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $stammnummer
 * @property string|null $vin
 * @property string|null $make
 * @property string|null $model
 * @property string|null $variant
 * @property string|null $internal_label
 * @property string|null $type_approval
 * @property BodyType|null $body_type
 * @property VehicleType $vehicle_type
 * @property FuelType|null $fuel
 * @property Transmission|null $transmission
 * @property DriveType|null $drive
 * @property int|null $power_kw
 * @property int|null $displacement_cc
 * @property int|null $doors
 * @property int|null $seats
 * @property string|null $color_exterior
 * @property string|null $color_interior
 * @property Carbon|null $first_registration_on
 * @property Carbon|null $last_registration_on
 * @property string|null $plate
 * @property int|null $curb_weight_kg
 * @property int|null $total_weight_kg
 * @property int|null $keys_count
 * @property Carbon|null $mfk_last_on
 * @property Carbon|null $mfk_due_on
 * @property Carbon|null $service_last_on
 * @property int|null $service_last_km
 * @property Carbon|null $service_next_on
 * @property list<string>|null $equipment
 * @property string|null $internal_notes
 */
#[UseFactory(VehicleFactory::class)]
class Vehicle extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    use HasTranslations;
    use HasUuids;
    use TracksAuthors;

    /** @var list<string> */
    public array $translatable = ['description'];

    protected $fillable = [
        'tenant_id',
        'stammnummer',
        'vin',
        'make',
        'model',
        'variant',
        'internal_label',
        'type_approval',
        'body_type',
        'vehicle_type',
        'fuel',
        'transmission',
        'drive',
        'power_kw',
        'displacement_cc',
        'doors',
        'seats',
        'color_exterior',
        'color_interior',
        'first_registration_on',
        'last_registration_on',
        'plate',
        'curb_weight_kg',
        'total_weight_kg',
        'keys_count',
        'mfk_last_on',
        'mfk_due_on',
        'service_last_on',
        'service_last_km',
        'service_next_on',
        'equipment',
        'description',
        'internal_notes',
    ];

    protected $attributes = [
        'vehicle_type' => 'passenger_car',
    ];

    protected function casts(): array
    {
        return [
            'body_type' => BodyType::class,
            'vehicle_type' => VehicleType::class,
            'fuel' => FuelType::class,
            'transmission' => Transmission::class,
            'drive' => DriveType::class,
            'power_kw' => 'integer',
            'displacement_cc' => 'integer',
            'doors' => 'integer',
            'seats' => 'integer',
            'curb_weight_kg' => 'integer',
            'total_weight_kg' => 'integer',
            'keys_count' => 'integer',
            'service_last_km' => 'integer',
            'first_registration_on' => 'date',
            'last_registration_on' => 'date',
            'mfk_last_on' => 'date',
            'mfk_due_on' => 'date',
            'service_last_on' => 'date',
            'service_next_on' => 'date',
            'equipment' => 'array',
        ];
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function stammnummer(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => Stammnummer::normalize($value));
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function vin(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => Vin::normalize($value));
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function plate(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => blank($value) ? null : strtoupper(trim($value)));
    }

    /**
     * @return HasMany<StockCycle, $this>
     */
    public function stockCycles(): HasMany
    {
        return $this->hasMany(StockCycle::class);
    }

    /**
     * @return HasOne<StockCycle, $this>
     */
    public function openStockCycle(): HasOne
    {
        return $this->hasOne(StockCycle::class)->whereIn('status', StockCycleStatus::openValues());
    }

    /**
     * @return HasMany<TyreSet, $this>
     */
    public function tyreSets(): HasMany
    {
        return $this->hasMany(TyreSet::class);
    }

    /**
     * "BMW X3 xDrive30i", or the dealer's own label when make and model are not recorded yet.
     */
    public function displayName(): string
    {
        $name = trim(implode(' ', array_filter([$this->make, $this->model, $this->variant])));

        if ($name !== '') {
            return $name;
        }

        return $this->internal_label ?? __('Vehicle without name');
    }

    public function formattedStammnummer(): ?string
    {
        return Stammnummer::format($this->stammnummer);
    }
}
