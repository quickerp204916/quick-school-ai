<?php

namespace Modules\Platform\Services;

use App\Core\Audit\AuditLog;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Models\PlatformAdmin;
use Modules\Platform\Models\PlatformAuditLog;
use Modules\Platform\Models\Tenant;

final class PlatformAudit
{
    /** Record an action in the platform audit log. */
    public static function record(string $action, ?Model $subject = null, ?string $tenantId = null, array $changes = [], ?PlatformAdmin $admin = null): PlatformAuditLog
    {
        $admin ??= auth('platform')->user();

        return PlatformAuditLog::create([
            'platform_admin_id' => $admin?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'tenant_id' => $tenantId,
            'changes' => $changes ?: null,
            'ip_address' => app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip(),
            'via' => self::channel(),
        ]);
    }

    /** Record an action in the institution's own audit log, so its owner can see it. */
    public static function toTenant(Tenant $tenant, string $action, ?PlatformAdmin $admin = null, array $changes = [], ?Model $subject = null): void
    {
        $admin ??= auth('platform')->user();

        TenantContext::run($tenant, fn () => AuditLog::create([
            'actor_type' => $admin ? 'platform_admin' : 'system',
            'actor_id' => $admin?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'changes' => $changes ?: null,
            'ip_address' => app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip(),
        ]));
    }

    private static function channel(): string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return 'cli';
        }

        return request()->is('api/*') ? 'api' : 'web';
    }
}
