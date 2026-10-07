<?php

namespace App\Domain\Tenancy\Database;

use Illuminate\Support\Facades\DB;

/**
 * Helpers used by migrations to protect tenant-owned tables with PostgreSQL RLS.
 *
 * Every tenant-owned table gets FORCE ROW LEVEL SECURITY (so even the table
 * owner is subject to it) and one policy that allows a row only when its
 * tenant_id matches the session setting app.tenant_id, or when the session
 * explicitly bypasses isolation (app.bypass_rls = 'on').
 */
class RowLevelSecurity
{
    public const POLICY = 'tenant_isolation';

    public static function installFunctions(): void
    {
        if (! self::isPostgres()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_rls_bypassed() RETURNS boolean
                LANGUAGE sql STABLE
                AS $$ SELECT coalesce(current_setting('app.bypass_rls', true), 'off') = 'on' $$;

            CREATE OR REPLACE FUNCTION app_current_tenant() RETURNS uuid
                LANGUAGE sql STABLE
                AS $$ SELECT nullif(current_setting('app.tenant_id', true), '')::uuid $$;

            CREATE OR REPLACE FUNCTION app_rls_allows(row_tenant uuid) RETURNS boolean
                LANGUAGE sql STABLE
                AS $$ SELECT app_rls_bypassed() OR (row_tenant IS NOT NULL AND row_tenant = app_current_tenant()) $$;

            CREATE OR REPLACE FUNCTION app_append_only() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'Table % is append-only: % is not allowed', TG_TABLE_NAME, TG_OP
                        USING ERRCODE = 'insufficient_privilege';
                END;
                $$;
            SQL);
    }

    public static function dropFunctions(): void
    {
        if (! self::isPostgres()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS app_append_only();
            DROP FUNCTION IF EXISTS app_rls_allows(uuid);
            DROP FUNCTION IF EXISTS app_current_tenant();
            DROP FUNCTION IF EXISTS app_rls_bypassed();
            SQL);
    }

    public static function enable(string $table, string $column = 'tenant_id'): void
    {
        if (! self::isPostgres()) {
            return;
        }

        $policy = self::POLICY;

        DB::unprepared(<<<SQL
            ALTER TABLE "{$table}" ENABLE ROW LEVEL SECURITY;
            ALTER TABLE "{$table}" FORCE ROW LEVEL SECURITY;
            CREATE POLICY {$policy} ON "{$table}"
                USING (app_rls_allows("{$column}"))
                WITH CHECK (app_rls_allows("{$column}"));
            SQL);
    }

    public static function disable(string $table): void
    {
        if (! self::isPostgres()) {
            return;
        }

        $policy = self::POLICY;

        DB::unprepared(<<<SQL
            DROP POLICY IF EXISTS {$policy} ON "{$table}";
            ALTER TABLE "{$table}" NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE "{$table}" DISABLE ROW LEVEL SECURITY;
            SQL);
    }

    /**
     * Block UPDATE and DELETE on a table (audit log, status history).
     */
    public static function makeAppendOnly(string $table): void
    {
        if (! self::isPostgres()) {
            return;
        }

        DB::unprepared(<<<SQL
            CREATE TRIGGER {$table}_append_only
                BEFORE UPDATE OR DELETE ON "{$table}"
                FOR EACH ROW EXECUTE FUNCTION app_append_only();
            SQL);
    }

    private static function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
