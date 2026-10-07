<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/**
 * Holds the tenant of the current request or job and mirrors it into the
 * PostgreSQL session settings that the Row-Level Security policies read.
 *
 * - app.tenant_id  = the current tenant's UUID ('' when none)
 * - app.bypass_rls = 'on' only for platform administration, migrations and seeders
 *
 * Application scopes and RLS both fail closed: without a tenant and without
 * bypass, tenant-owned tables return no rows and reject writes.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $bypass = false;

    public function __construct(private readonly DatabaseManager $db) {}

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->applyTenantSetting();
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->bypass = false;
        $this->applyTenantSetting();
        $this->applyBypassSetting();
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?string
    {
        return $this->tenant?->getKey();
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function isBypassed(): bool
    {
        return $this->bypass;
    }

    public function enableBypass(): void
    {
        $this->bypass = true;
        $this->applyBypassSetting();
    }

    public function disableBypass(): void
    {
        $this->bypass = false;
        $this->applyBypassSetting();
    }

    /**
     * Run a callback as the given tenant, restoring the previous context afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousBypass = $this->bypass;

        $this->set($tenant);

        if ($previousBypass) {
            $this->disableBypass();
        }

        try {
            return $callback();
        } finally {
            $this->set($previousTenant);

            if ($previousBypass) {
                $this->enableBypass();
            }
        }
    }

    /**
     * Run a callback with tenant isolation switched off (platform administration only).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function bypass(Closure $callback): mixed
    {
        $previous = $this->bypass;

        $this->enableBypass();

        try {
            return $callback();
        } finally {
            if (! $previous) {
                $this->disableBypass();
            }
        }
    }

    /**
     * Re-apply the PostgreSQL settings, e.g. after a reconnect.
     */
    public function reapply(): void
    {
        $this->applyTenantSetting();
        $this->applyBypassSetting();
    }

    private function applyTenantSetting(): void
    {
        $this->setDatabaseSetting('app.tenant_id', $this->tenant?->getKey() ?? '');
    }

    private function applyBypassSetting(): void
    {
        $this->setDatabaseSetting('app.bypass_rls', $this->bypass ? 'on' : 'off');
    }

    private function setDatabaseSetting(string $name, string $value): void
    {
        $connection = $this->db->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            $connection->select('select set_config(?, ?, false)', [$name, $value]);
        } catch (QueryException $e) {
            // 25P02: the surrounding transaction already failed. Swallow this so the original
            // error surfaces; settings are re-applied after the rollback (see AppServiceProvider).
            if ($e->getCode() !== '25P02') {
                throw $e;
            }
        }
    }
}
