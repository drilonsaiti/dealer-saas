<?php

namespace App\Domain\Vehicles\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Audit\Models\StatusHistory;
use App\Domain\Documents\Models\Document;
use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\TradeIn;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use Database\Factories\StockCycleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;

/**
 * One purchase-to-sale pass of a vehicle (Vorgang / Fahrzeugakte). Everything financial
 * (purchase, costs, sale, payments, documents) hangs off the cycle, not the vehicle.
 *
 * Status, number and the milestone dates are not mass-assignable: they change only through
 * TransitionStockCycle (or an import), never by editing a form field.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vehicle_id
 * @property string|null $number
 * @property StockCycleStatus $status
 * @property int|null $file_year
 * @property Carbon|null $purchased_on
 * @property Carbon|null $ready_on
 * @property Carbon|null $listed_on
 * @property Carbon|null $sold_on
 * @property Carbon|null $delivered_on
 * @property Carbon|null $archived_on
 * @property int|null $planned_price_rp
 * @property int|null $list_price_rp
 * @property int|null $mileage_in
 * @property int|null $mileage_out
 * @property string|null $notes
 * @property string|null $legacy_ref
 * @property-read Vehicle $vehicle
 * @property-read Purchase|null $purchase
 * @property-read Sale|null $activeSale
 * @property-read TradeIn|null $tradeInSource
 */
#[UseFactory(StockCycleFactory::class)]
class StockCycle extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<StockCycleFactory> */
    use HasFactory;

    use HasUuids;
    use TracksAuthors;

    protected $fillable = [
        'tenant_id',
        'vehicle_id',
        'planned_price_rp',
        'list_price_rp',
        'mileage_in',
        'notes',
        'legacy_ref',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockCycleStatus::class,
            'file_year' => 'integer',
            'purchased_on' => 'date:Y-m-d',
            'ready_on' => 'date:Y-m-d',
            'listed_on' => 'date:Y-m-d',
            'sold_on' => 'date:Y-m-d',
            'delivered_on' => 'date:Y-m-d',
            'archived_on' => 'date:Y-m-d',
            'planned_price_rp' => 'integer',
            'list_price_rp' => 'integer',
            'mileage_in' => 'integer',
            'mileage_out' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * The tyre sets belong to the car, so every cycle of it shows them.
     *
     * @return HasMany<TyreSet, $this>
     */
    public function tyreSets(): HasMany
    {
        return $this->hasMany(TyreSet::class, 'vehicle_id', 'vehicle_id');
    }

    /**
     * @return HasOne<Purchase, $this>
     */
    public function purchase(): HasOne
    {
        return $this->hasOne(Purchase::class);
    }

    /**
     * The current (not cancelled) sale; the database allows only one.
     *
     * @return HasOne<Sale, $this>
     */
    public function activeSale(): HasOne
    {
        return $this->hasOne(Sale::class)->where('status', '<>', 'cancelled');
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * When this car came in as a trade-in: the trade-in record on the other car's sale.
     *
     * @return HasOne<TradeIn, $this>
     */
    public function tradeInSource(): HasOne
    {
        return $this->hasOne(TradeIn::class, 'purchase_cycle_id');
    }

    /**
     * @return HasMany<Cost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(Cost::class);
    }

    /**
     * @return HasMany<Commitment, $this>
     */
    public function commitments(): HasMany
    {
        return $this->hasMany(Commitment::class);
    }

    /**
     * Documents of this file (a document can also belong to a party or another file).
     *
     * @return MorphToMany<Document, $this>
     */
    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'linkable', 'document_links');
    }

    /**
     * @return MorphMany<StatusHistory, $this>
     */
    public function statusHistory(): MorphMany
    {
        return $this->morphMany(StatusHistory::class, 'subject')->latest('created_at');
    }

    /**
     * @param  Builder<StockCycle>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', StockCycleStatus::openValues());
    }

    /**
     * @param  Builder<StockCycle>  $query
     */
    public function scopeInStock(Builder $query): void
    {
        $query->whereIn('status', StockCycleStatus::inStockValues());
    }

    public function isLocked(): bool
    {
        return $this->status->isLocked();
    }

    /**
     * Days from purchase until sale (or until today while the car is still in stock).
     */
    public function daysInStock(?Carbon $today = null): ?int
    {
        if ($this->purchased_on === null) {
            return null;
        }

        $end = $this->sold_on ?? $this->delivered_on ?? ($today ?? Carbon::today());

        return (int) $this->purchased_on->diffInDays($end);
    }

    public function title(): string
    {
        $name = $this->vehicle->displayName();

        return $this->number === null ? $name : "{$this->number} · {$name}";
    }
}
