<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One monthly restore drill (acceptance test 13), written by deploy/restore-test.sh.
 * Platform data: no tenant, shown only in the platform panel.
 *
 * @property string $id
 * @property string $status
 * @property string|null $backup_file
 * @property int|null $tenants
 * @property int|null $audit_rows
 * @property int|null $rls_tables
 * @property int|null $duration_seconds
 * @property string|null $message
 * @property Carbon $ran_at
 */
class RestoreDrill extends Model
{
    use HasUuids;

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    /** A drill is due every month; after this many days without a good one, the platform warns. */
    public const OVERDUE_AFTER_DAYS = 35;

    protected $fillable = ['status', 'backup_file', 'tenants', 'audit_rows', 'rls_tables', 'duration_seconds', 'message', 'ran_at'];

    protected function casts(): array
    {
        return [
            'tenants' => 'integer',
            'audit_rows' => 'integer',
            'rls_tables' => 'integer',
            'duration_seconds' => 'integer',
            'ran_at' => 'datetime',
        ];
    }

    public static function lastSuccessful(): ?self
    {
        return self::query()->where('status', self::STATUS_OK)->latest('ran_at')->first();
    }

    public static function isOverdue(): bool
    {
        $last = self::lastSuccessful();

        return $last === null || $last->ran_at->lt(now()->subDays(self::OVERDUE_AFTER_DAYS));
    }
}
