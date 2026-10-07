<?php

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

class MissingTenantContext extends RuntimeException
{
    public static function forCreating(string $model): self
    {
        return new self("Cannot create [{$model}] without a tenant context. Run it inside TenantContext::run() or set tenant_id explicitly.");
    }
}
