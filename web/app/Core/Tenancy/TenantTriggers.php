<?php

namespace App\Core\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Migration helper: database-level guard for tenant tables.
 *
 * MySQL (no Row-Level Security): BEFORE INSERT/UPDATE triggers reject any row whose tenant_id
 * differs from @app_tenant_id, or when it is not set. Reads rely on the Eloquent scope.
 * On Amazon RDS, set log_bin_trust_function_creators = 1 so the migration user can create triggers.
 *
 * PostgreSQL: Row-Level Security, forced even for the table owner. Rows are visible and writable
 * only when tenant_id equals the app.tenant_id setting. Platform code may read (never write)
 * across tenants inside TenantContext::acrossTenants(). The application must connect as a role
 * that is not a superuser and does not have BYPASSRLS.
 *
 * SQLite (local tests only): no database guard; the Eloquent scope still applies.
 */
final class TenantTriggers
{
    public const POLICY = 'tenant_isolation';

    public static function create(string $table): void
    {
        match (DB::connection()->getDriverName()) {
            'mysql' => self::createMysql($table),
            'pgsql' => self::createPgsql($table),
            default => null,
        };
    }

    public static function drop(string $table): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            foreach (['insert', 'update'] as $event) {
                DB::unprepared('DROP TRIGGER IF EXISTS `'.self::name($table, $event).'`');
            }
        } elseif ($driver === 'pgsql') {
            DB::unprepared("DROP POLICY IF EXISTS ".self::POLICY." ON \"{$table}\"");
            DB::unprepared("DROP POLICY IF EXISTS ".self::POLICY."_read ON \"{$table}\"");
            DB::unprepared("ALTER TABLE \"{$table}\" NO FORCE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE \"{$table}\" DISABLE ROW LEVEL SECURITY");
        }
    }

    /** @return list<string> tenant tables missing their database guard */
    public static function missing(array $tables): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $ok = collect(DB::select(
                'SELECT c.relname AS name FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                 JOIN pg_policies p ON p.tablename = c.relname AND p.schemaname = n.nspname AND p.policyname = ?
                 WHERE n.nspname = current_schema() AND c.relrowsecurity AND c.relforcerowsecurity', [self::POLICY]
            ))->pluck('name')->all();

            return array_values(array_diff($tables, $ok));
        }

        $existing = collect(DB::select('SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'))
            ->pluck('name')->all();

        return array_values(array_filter($tables, fn ($t) => ! in_array(self::name($t, 'insert'), $existing, true)
            || ! in_array(self::name($t, 'update'), $existing, true)));
    }

    private static function createMysql(string $table): void
    {
        foreach (['insert', 'update'] as $event) {
            $name = self::name($table, $event);
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            DB::unprepared(
                "CREATE TRIGGER `{$name}` BEFORE ".strtoupper($event)." ON `{$table}` FOR EACH ROW\n".
                "BEGIN\n".
                "  IF @app_tenant_id IS NULL OR CAST(NEW.tenant_id AS BINARY) <> CAST(@app_tenant_id AS BINARY) THEN\n".
                "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tenant mismatch on {$table}';\n".
                "  END IF;\n".
                'END'
            );
        }
    }

    private static function createPgsql(string $table): void
    {
        $current = "NULLIF(current_setting('app.tenant_id', true), '')::uuid";
        DB::unprepared("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
        DB::unprepared("ALTER TABLE \"{$table}\" FORCE ROW LEVEL SECURITY");
        DB::unprepared("DROP POLICY IF EXISTS ".self::POLICY." ON \"{$table}\"");
        DB::unprepared("DROP POLICY IF EXISTS ".self::POLICY."_read ON \"{$table}\"");
        // Reads, writes and deletes: only the current tenant's rows.
        DB::unprepared("CREATE POLICY ".self::POLICY." ON \"{$table}\" FOR ALL USING (tenant_id = {$current}) WITH CHECK (tenant_id = {$current})");
        // Extra read-only path for platform code inside TenantContext::acrossTenants().
        DB::unprepared("CREATE POLICY ".self::POLICY."_read ON \"{$table}\" FOR SELECT USING (current_setting('app.cross_tenant', true) = 'on')");
    }

    private static function name(string $table, string $event): string
    {
        return "{$table}_tenant_{$event}";
    }
}
