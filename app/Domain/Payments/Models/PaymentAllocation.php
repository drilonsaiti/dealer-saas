<?php

namespace App\Domain\Payments\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $payment_id
 * @property string $allocatable_type
 * @property string $allocatable_id
 * @property int $amount_rp
 * @property-read Payment $payment
 * @property-read Model $allocatable
 */
class PaymentAllocation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'payment_id', 'allocatable_type', 'allocatable_id', 'amount_rp'];

    protected function casts(): array
    {
        return ['amount_rp' => 'integer'];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function allocatable(): MorphTo
    {
        return $this->morphTo();
    }
}
