<?php

namespace App\Core\Tenancy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Platform\Models\Tenant;

/**
 * Holds the current tenant for this request or job, and mirrors it into the database
 * session so the database itself can enforce isolation:
 *  - MySQL: session variable @app_tenant_id, checked by the tenant write triggers.
 *  - PostgreSQL: setting app.tenant_id, checked by the Row-Level Security policies.
 *
 * Always use run() so the previous context is restored, even on exceptions.
 */
final class TenantContext
{
    private static ?string $tenantId = null;

    public static function id(): ?string
    {
        return self::$tenantId;
    }

    public static function check(): bool
    {
        return self::$tenantId !== null;
    }

    /**
     * Run $callback as $tenant. Nested calls restore the outer tenant afterwards.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(Tenant|string $tenant, callable $callback): mixed
    {
        $id = $tenant instanceof Tenant ? (string) $tenant->getKey() : $tenant;
        $previous = self::$tenantId;

        self::set($id);

        try {
            return $callback();
        } finally {
            self::set($previous);
        }
    }

    /**
     * Platform-only: read across all institutions on PostgreSQL, where RLS would otherwise hide
     * other tenants' rows. Writes still need a matching tenant. Combine with
     * withoutGlobalScope('tenant') so the intent is visible in review. No-op on other drivers.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function acrossTenants(callable $callback): mixed
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $callback();
        }

        $previous = DB::selectOne("SELECT current_setting('app.cross_tenant', true) AS v")->v;
        DB::select("SELECT set_config('app.cross_tenant', 'on', false)");

        try {
            return $callback();
        } finally {
            DB::select("SELECT set_config('app.cross_tenant', ?, false)", [$previous === 'on' ? 'on' : '']);
        }
    }

    private static function set(?string $id): void
    {
        if ($id !== null && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            throw new InvalidArgumentException('Tenant id must be a UUID.');
        }

        self::$tenantId = $id;

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            // Value is a validated UUID, so it is safe to quote directly.
            DB::unprepared('SET @app_tenant_id = '.($id === null ? 'NULL' : DB::getPdo()->quote(strtolower($id))));
        } elseif ($driver === 'pgsql') {
            self::syncPostgres();
        }
    }

    /**
     * PostgreSQL undoes setting changes when a transaction or savepoint rolls back, and refuses
     * any statement while a transaction is aborted. So: skip the write while aborted, and
     * re-apply the current value after every rollback (AppServiceProvider listens for it).
     */
    public static function syncPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::select("SELECT set_config('app.tenant_id', ?, false)", [self::$tenantId === null ? '' : strtolower(self::$tenantId)]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '25P02') {   // in_failed_sql_transaction: re-applied after the rollback
                throw $e;
            }
        }
    }

    /** What the database currently holds as the tenant (for tests and diagnostics). */
    public static function databaseValue(): ?string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql' => DB::selectOne('SELECT @app_tenant_id AS t')->t,
            'pgsql' => (DB::selectOne("SELECT current_setting('app.tenant_id', true) AS t")->t ?: null),
            default => self::$tenantId,
        };
    }
}
