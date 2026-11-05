<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * For every model that stores institution data.
 *
 * - Every query is filtered to the current tenant. With no tenant set, queries
 *   fail instead of returning other institutions' rows.
 * - New rows get tenant_id from the context, and cannot be created without one.
 *
 * Platform code that genuinely needs cross-tenant reads must call
 * Model::withoutGlobalScope('tenant') explicitly, so it is visible in review.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $tenantId = TenantContext::id();

            if ($tenantId === null) {
                throw new LogicException('No tenant context for '.static::class.'. Wrap the call in TenantContext::run().');
            }

            $query->where($query->qualifyColumn('tenant_id'), $tenantId);
        });

        static::creating(function ($model): void {
            $tenantId = TenantContext::id();

            if ($tenantId === null) {
                throw new LogicException('Cannot create '.static::class.' without a tenant context.');
            }

            if (empty($model->tenant_id)) {
                $model->tenant_id = $tenantId;
            } elseif (strtolower((string) $model->tenant_id) !== strtolower($tenantId)) {
                throw new LogicException('tenant_id does not match the current tenant context.');
            }
        });
    }
}
