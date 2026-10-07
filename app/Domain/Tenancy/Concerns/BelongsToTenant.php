<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every model whose table has a tenant_id column.
 *
 * - Reads are limited to the current tenant (TenantScope); without a tenant they return nothing.
 * - New records get the current tenant's id; creating one without a tenant context throws.
 * - PostgreSQL RLS enforces the same rule underneath, in case a query skips Eloquent.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if (filled($model->getAttribute('tenant_id'))) {
                return;
            }

            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                throw MissingTenantContext::forCreating($model::class);
            }

            $model->setAttribute('tenant_id', $tenantId);
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
