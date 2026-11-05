<?php

namespace App\Core\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Migration helper: database-level guard for tenant tables.
 *
 * Rejects any INSERT or UPDATE whose tenant_id differs from @app_tenant_id,
 * or when @app_tenant_id is not set. This catches raw SQL and bugs that bypass
 * the Eloquent scope. MySQL only; other drivers are skipped.
 *
 * On Amazon RDS, set log_bin_trust_function_creators = 1 in the parameter group
 * so the migration user can create triggers.
 */
final class TenantTriggers
{
    public static function create(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

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

    public static function drop(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (['insert', 'update'] as $event) {
            DB::unprepared('DROP TRIGGER IF EXISTS `'.self::name($table, $event).'`');
        }
    }

    /** @return list<string> tenant tables missing either trigger */
    public static function missing(array $tables): array
    {
        $existing = collect(DB::select('SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'))
            ->pluck('name')->all();

        return array_values(array_filter($tables, fn ($t) => ! in_array(self::name($t, 'insert'), $existing, true)
            || ! in_array(self::name($t, 'update'), $existing, true)));
    }

    private static function name(string $table, string $event): string
    {
        return "{$table}_tenant_{$event}";
    }
}
