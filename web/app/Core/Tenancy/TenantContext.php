<?php

namespace App\Core\Tenancy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Platform\Models\Tenant;

/**
 * Holds the current tenant for this request or job, and mirrors it into the
 * MySQL session variable @app_tenant_id that the tenant write triggers check.
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

    private static function set(?string $id): void
    {
        if ($id !== null && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            throw new InvalidArgumentException('Tenant id must be a UUID.');
        }

        self::$tenantId = $id;

        if (DB::connection()->getDriverName() === 'mysql') {
            // Value is a validated UUID, so it is safe to quote directly.
            DB::unprepared('SET @app_tenant_id = '.($id === null ? 'NULL' : DB::getPdo()->quote(strtolower($id))));
        }
    }
}
