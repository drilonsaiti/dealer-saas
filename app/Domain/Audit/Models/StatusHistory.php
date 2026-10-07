<?php

namespace App\Domain\Audit\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One row per status change of any record (vehicle cycle, document, invoice, VAT period ...).
 * Append-only, like the audit log.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property string|null $user_id
 * @property Carbon $created_at
 */
class StatusHistory extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'status_history';

    protected $fillable = [
        'tenant_id',
        'subject_type',
        'subject_id',
        'from_status',
        'to_status',
        'reason',
        'user_id',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(Model $subject, ?string $from, string $to, ?string $reason = null): self
    {
        return self::create([
            'tenant_id' => $subject->getAttribute('tenant_id'),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'user_id' => auth()->id(),
        ]);
    }
}
