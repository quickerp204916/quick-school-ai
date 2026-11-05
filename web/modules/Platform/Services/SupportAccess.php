<?php

namespace Modules\Platform\Services;

use Modules\Platform\Exceptions\PlatformRuleException;
use Modules\Platform\Models\PlatformAdmin;
use Modules\Platform\Models\SupportSession;
use Modules\Platform\Models\Tenant;

/**
 * Time-limited, reasoned access by platform staff to an institution's data.
 * Every start and end is written to the institution's own audit log.
 */
final class SupportAccess
{
    public function start(PlatformAdmin $admin, Tenant $tenant, string $reason, int $minutes): SupportSession
    {
        $max = (int) config('platform.support.max_session_minutes');
        if ($minutes < 5 || $minutes > $max) {
            throw new PlatformRuleException("Support sessions last between 5 and {$max} minutes.");
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw new PlatformRuleException('Describe the reason in at least 10 characters, for example the ticket number and problem.');
        }
        if ($tenant->status === 'archived') {
            throw new PlatformRuleException('Archived institutions cannot be accessed.');
        }
        if ($this->activeFor($admin, $tenant)) {
            throw new PlatformRuleException('You already have an active support session for this institution.');
        }

        $session = SupportSession::create([
            'tenant_id' => $tenant->id, 'platform_admin_id' => $admin->id, 'reason' => trim($reason),
            'started_at' => now(), 'expires_at' => now()->addMinutes($minutes),
        ]);

        $details = ['reason' => $session->reason, 'expires_at' => $session->expires_at->toIso8601String(), 'staff' => $admin->name];
        PlatformAudit::toTenant($tenant, 'support.session_started', $admin, $details, $session);
        PlatformAudit::record('support.session_started', $session, $tenant->id, $details, $admin);

        return $session;
    }

    public function end(SupportSession $session, PlatformAdmin $by): SupportSession
    {
        if ($session->ended_at) {
            throw new PlatformRuleException('This session has already ended.');
        }
        if ($session->platform_admin_id !== $by->id && ! $by->hasRole('super_admin')) {
            throw new PlatformRuleException('Only the staff member who opened it or a super admin can end this session.');
        }

        $session->update(['ended_at' => now()]);
        PlatformAudit::toTenant($session->tenant, 'support.session_ended', $by, [], $session);
        PlatformAudit::record('support.session_ended', $session, $session->tenant_id, [], $by);

        return $session;
    }

    public function activeFor(PlatformAdmin $admin, Tenant $tenant): ?SupportSession
    {
        return SupportSession::where('platform_admin_id', $admin->id)->where('tenant_id', $tenant->id)
            ->whereNull('ended_at')->where('expires_at', '>', now())->first();
    }
}
