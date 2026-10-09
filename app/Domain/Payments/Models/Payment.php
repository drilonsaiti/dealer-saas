<?php

namespace App\Domain\Payments\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Parties\Models\Party;
use App\Domain\Payments\Enums\PaymentDirection;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money in or out, allocated to invoices, purchases or costs (one payment can cover several,
 * e.g. CHF 22'800 by bank plus CHF 5'000 cash for one purchase are two payments).
 *
 * @property string $id
 * @property string $tenant_id
 * @property PaymentDirection $direction
 * @property Carbon $paid_on
 * @property int $amount_rp
 * @property PaymentMethod $method
 * @property string|null $party_id
 * @property string|null $bank_account_id
 * @property string|null $reference
 * @property string|null $bank_transaction_id
 * @property string|null $notes
 * @property string|null $legacy_ref
 * @property-read Collection<int, PaymentAllocation> $allocations
 * @property-read Party|null $party
 * @property-read BankAccount|null $bankAccount
 */
class Payment extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    protected $fillable = ['tenant_id', 'direction', 'paid_on', 'amount_rp', 'method', 'party_id', 'bank_account_id', 'reference', 'bank_transaction_id', 'notes', 'legacy_ref'];

    protected function casts(): array
    {
        return [
            'direction' => PaymentDirection::class,
            'method' => PaymentMethod::class,
            'paid_on' => 'date:Y-m-d',
            'amount_rp' => 'integer',
        ];
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function unallocatedRp(): int
    {
        return $this->amount_rp - (int) $this->allocations()->sum('amount_rp');
    }
}
