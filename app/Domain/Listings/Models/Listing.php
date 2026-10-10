<?php

namespace App\Domain\Listings\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Documents\Models\Document;
use App\Domain\Listings\Enums\Availability;
use App\Domain\Listings\Enums\ListingStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * The public advert of a vehicle file: texts per language, price, chosen photos (first =
 * cover). Whether it is available, reserved or sold always follows the vehicle file, so the
 * website and portals can never show a sold car as available.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $stock_cycle_id
 * @property ListingStatus $status
 * @property string $title
 * @property string|null $description
 * @property array<int, string>|null $highlights
 * @property int $price_rp
 * @property bool $show_price
 * @property list<string>|null $photo_document_ids
 * @property Carbon|null $published_at
 * @property Carbon|null $withdrawn_at
 * @property-read StockCycle $stockCycle
 * @property-read Collection<int, ListingPublication> $publications
 */
class Listing extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasTranslations;
    use HasUuids;
    use TracksAuthors;

    /** @var list<string> */
    public array $translatable = ['title', 'description'];

    protected $fillable = ['tenant_id', 'stock_cycle_id', 'title', 'description', 'highlights', 'price_rp', 'show_price', 'photo_document_ids'];

    protected $attributes = ['status' => 'draft', 'show_price' => true];

    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'highlights' => 'array',
            'photo_document_ids' => 'array',
            'price_rp' => 'integer',
            'show_price' => 'boolean',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public static function soldVisibleDays(): int
    {
        return (int) config('dealer.listings.sold_visible_days', 7);
    }

    /**
     * Published and, by the vehicle file, available, reserved or sold within the last days.
     *
     * @param  Builder<Listing>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), ListingStatus::Published->value)
            ->whereHas('stockCycle', fn (Builder $cycle) => $cycle
                ->whereIn('status', [StockCycleStatus::Listed->value, StockCycleStatus::ReadyForSale->value, StockCycleStatus::Reserved->value])
                ->orWhere(fn (Builder $sold) => $sold
                    ->whereIn('status', [StockCycleStatus::Sold->value, StockCycleStatus::Delivered->value, StockCycleStatus::Archived->value])
                    ->whereDate('sold_on', '>=', Carbon::today()->subDays(self::soldVisibleDays()))));
    }

    public function availability(): Availability
    {
        if ($this->status !== ListingStatus::Published) {
            return Availability::Hidden;
        }

        $cycle = $this->stockCycle;

        return match ($cycle->status) {
            StockCycleStatus::Listed, StockCycleStatus::ReadyForSale => Availability::Available,
            StockCycleStatus::Reserved => Availability::Reserved,
            StockCycleStatus::Sold, StockCycleStatus::Delivered, StockCycleStatus::Archived => $cycle->sold_on !== null && $cycle->sold_on->greaterThanOrEqualTo(Carbon::today()->subDays(self::soldVisibleDays()))
                ? Availability::Sold
                : Availability::Hidden,
            default => Availability::Hidden,
        };
    }

    /**
     * The chosen photos in their order (documents of category "photo" in the file).
     *
     * @return Collection<int, Document>
     */
    public function photos(): Collection
    {
        $ids = $this->photo_document_ids ?? [];

        if ($ids === []) {
            return new Collection;
        }

        $documents = Document::query()->with('currentVersion')->whereIn('id', $ids)->get()->keyBy('id');

        return new Collection(array_values(array_filter(array_map(fn (string $id): ?Document => $documents->get($id), $ids))));
    }

    /**
     * @return BelongsTo<StockCycle, $this>
     */
    public function stockCycle(): BelongsTo
    {
        return $this->belongsTo(StockCycle::class);
    }

    /**
     * @return HasMany<ListingPublication, $this>
     */
    public function publications(): HasMany
    {
        return $this->hasMany(ListingPublication::class);
    }
}
