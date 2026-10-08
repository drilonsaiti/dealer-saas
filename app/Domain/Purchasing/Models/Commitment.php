<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use App\Models\User;
use Database\Factories\CommitmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Zusage: a promise to the customer ("4 new summer tyres", "fix dent"). Counts in the
 * margin with its estimated cost until a real cost replaces it, and blocks the handover
 * until it is done.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $stock_cycle_id
 * @property string|null $sale_id
 * @property string $description
 * @property int $estimated_cost_rp
 * @property string|null $cost_id
 * @property Carbon|null $due_on
 * @property Carbon|null $done_at
 * @property string|null $done_by
 * @property bool $blocks_handover
 * @property-read Cost|null $cost
 */
#[UseFactory(CommitmentFactory::class)]
class Commitment extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<CommitmentFactory> */
    use HasFactory;

    use HasUuids;
    use TracksAuthors;

    protected $fillable = [
        'tenant_id',
        'stock_cycle_id',
        'sale_id',
        'description',
        'estimated_cost_rp',
        'due_on',
        'blocks_handover',
    ];

    protected $attributes = [
        'estimated_cost_rp' => 0,
        'blocks_handover' => true,
    ];

    protected function casts(): array
    {
        return [
            'estimated_cost_rp' => 'integer',
            'due_on' => 'date:Y-m-d',
            'done_at' => 'datetime',
            'blocks_handover' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Commitment>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('done_at');
    }

    /**
     * @return BelongsTo<StockCycle, $this>
     */
    public function stockCycle(): BelongsTo
    {
        return $this->belongsTo(StockCycle::class);
    }

    /**
     * @return BelongsTo<Cost, $this>
     */
    public function cost(): BelongsTo
    {
        return $this->belongsTo(Cost::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /**
     * Still counted as an estimate in the margin: no real cost has replaced it yet.
     */
    public function countsAsEstimate(): bool
    {
        return $this->cost_id === null;
    }
}
