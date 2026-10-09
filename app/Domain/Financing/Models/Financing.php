<?php

namespace App\Domain\Financing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Checklists\Models\Checklist;
use App\Domain\Checklists\Models\ChecklistItem;
use App\Domain\Financing\Enums\FinancingKind;
use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Parties\Models\Party;
use App\Domain\Sales\Models\Sale;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Leasing or credit for a sale: the bank pays the dealer the cash price less the first
 * instalment the dealer collected from the customer (expected payout).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sale_id
 * @property string $partner_party_id
 * @property FinancingKind $kind
 * @property string|null $contract_number
 * @property FinancingStatus $status
 * @property Carbon $applied_on
 * @property int $cash_price_rp
 * @property int $collection_rp
 * @property int|null $term_months
 * @property int|null $km_per_year
 * @property int|null $residual_rp
 * @property string|null $nominal_rate
 * @property int|null $monthly_rate_rp
 * @property bool $has_buyback
 * @property Carbon|null $contract_received_on
 * @property Carbon|null $revocation_until
 * @property Carbon|null $documents_sent_on
 * @property Carbon|null $payout_due_on
 * @property int $payout_expected_rp
 * @property Carbon|null $payout_received_on
 * @property string|null $notes
 * @property-read Sale $sale
 * @property-read Party $partner
 * @property-read BuybackObligation|null $buyback
 */
class Financing extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    protected $fillable = ['tenant_id', 'sale_id', 'partner_party_id', 'kind', 'contract_number', 'applied_on', 'cash_price_rp', 'collection_rp', 'term_months', 'km_per_year', 'residual_rp', 'nominal_rate', 'monthly_rate_rp', 'has_buyback', 'notes'];

    protected $attributes = ['status' => 'applied', 'kind' => 'leasing'];

    protected function casts(): array
    {
        return [
            'kind' => FinancingKind::class,
            'status' => FinancingStatus::class,
            'applied_on' => 'date:Y-m-d',
            'contract_received_on' => 'date:Y-m-d',
            'revocation_until' => 'date:Y-m-d',
            'documents_sent_on' => 'date:Y-m-d',
            'payout_due_on' => 'date:Y-m-d',
            'payout_received_on' => 'date:Y-m-d',
            'cash_price_rp' => 'integer',
            'collection_rp' => 'integer',
            'residual_rp' => 'integer',
            'monthly_rate_rp' => 'integer',
            'payout_expected_rp' => 'integer',
            'term_months' => 'integer',
            'km_per_year' => 'integer',
            'has_buyback' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Financing>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotIn('status', [FinancingStatus::Rejected->value, FinancingStatus::Cancelled->value]);
    }

    /**
     * Payout still to come: contract on its way, not paid out yet.
     *
     * @param  Builder<Financing>  $query
     */
    public function scopeAwaitingPayout(Builder $query): void
    {
        $query->whereIn('status', [FinancingStatus::ContractReceived->value, FinancingStatus::Signed->value, FinancingStatus::DocumentsSent->value]);
    }

    public function inRevocationPeriod(?Carbon $on = null): bool
    {
        return $this->revocation_until !== null && $this->revocation_until->greaterThanOrEqualTo(($on ?? Carbon::today())->copy()->startOfDay());
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'partner_party_id');
    }

    /**
     * @return HasOne<BuybackObligation, $this>
     */
    public function buyback(): HasOne
    {
        return $this->hasOne(BuybackObligation::class);
    }

    /**
     * @return HasMany<Checklist, $this>
     */
    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class);
    }

    /**
     * The items of the partner checklist (what the bank needs).
     *
     * @return HasManyThrough<ChecklistItem, Checklist, $this>
     */
    public function checklistItems(): HasManyThrough
    {
        return $this->hasManyThrough(ChecklistItem::class, Checklist::class);
    }
}
