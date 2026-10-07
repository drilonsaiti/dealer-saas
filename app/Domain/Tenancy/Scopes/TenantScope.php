<?php

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $column = $model->qualifyColumn('tenant_id');

        if (! $context->hasTenant()) {
            // Fail closed: no tenant, no rows.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $context->id());
    }
}
