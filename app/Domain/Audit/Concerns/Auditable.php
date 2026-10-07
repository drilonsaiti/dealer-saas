<?php

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;

/**
 * Writes one append-only audit row per create, update and delete:
 * who, when, which record, old values and new values.
 * Hidden attributes (passwords, secrets) and timestamps are never logged.
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            self::writeAuditLog($model, 'created', [], self::auditableValues($model, $model->getAttributes()));
        });

        static::updated(function (Model $model): void {
            $changes = self::auditableValues($model, $model->getChanges());

            if ($changes === []) {
                return;
            }

            $original = Arr::only($model->getRawOriginal(), array_keys($changes));

            self::writeAuditLog($model, 'updated', self::auditableValues($model, $original), $changes);
        });

        static::deleted(function (Model $model): void {
            self::writeAuditLog($model, 'deleted', self::auditableValues($model, $model->getRawOriginal()), []);
        });
    }

    /**
     * @return MorphMany<AuditLog, $this>
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function auditableValues(Model $model, array $values): array
    {
        $excluded = array_merge(
            $model->getHidden(),
            [$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()],
            property_exists($model, 'auditExclude') ? (array) $model->auditExclude : [],
        );

        return Arr::except($values, $excluded);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private static function writeAuditLog(Model $model, string $event, array $old, array $new): void
    {
        // The tenant record itself has no tenant_id column; reading it would throw in strict mode.
        $tenantId = $model instanceof Tenant
            ? $model->getKey()
            : $model->getAttribute('tenant_id') ?? app(TenantContext::class)->id();

        AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => (string) $model->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : substr((string) request()->userAgent(), 0, 500),
        ]);
    }
}
