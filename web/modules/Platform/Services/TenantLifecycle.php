<?php

namespace Modules\Platform\Services;

use Illuminate\Support\Facades\DB;
use Modules\Platform\Exceptions\PlatformRuleException;
use Modules\Platform\Models\PlatformAdmin;
use Modules\Platform\Models\Subscription;
use Modules\Platform\Models\Tenant;

/** Suspend, reactivate and archive institutions, and keep their status in step with billing. */
final class TenantLifecycle
{
    public function suspend(Tenant $tenant, string $reason, ?PlatformAdmin $by = null): Tenant
    {
        if ($tenant->status === 'archived') {
            throw new PlatformRuleException('An archived institution cannot be suspended.');
        }
        if (trim($reason) === '') {
            throw new PlatformRuleException('Give a reason for the suspension.');
        }

        $before = $tenant->status;
        $tenant->update([
            'status' => 'suspended', 'suspended_at' => now(), 'suspension_type' => 'manual', 'suspension_reason' => $reason,
        ]);

        PlatformAudit::record('tenant.suspended', $tenant, $tenant->id, ['from' => $before, 'reason' => $reason], $by);
        PlatformAudit::toTenant($tenant, 'tenant.suspended', $by, ['reason' => $reason]);

        return $tenant;
    }

    /**
     * Lift any suspension. Lifting a billing suspension by hand returns the
     * subscription to past due with $graceDays more days to pay before billing
     * can suspend it again.
     */
    public function reactivate(Tenant $tenant, ?PlatformAdmin $by = null, int $graceDays = 7): Tenant
    {
        if ($tenant->status !== 'suspended') {
            throw new PlatformRuleException('Only a suspended institution can be reactivated.');
        }

        DB::transaction(function () use ($tenant, $by, $graceDays) {
            $subscription = $tenant->subscription()->first();
            if ($subscription && $subscription->status === 'cancelled') {
                throw new PlatformRuleException('The subscription is cancelled. Start a new subscription to reactivate.');
            }
            if ($subscription && $subscription->status === 'suspended') {
                $subscription->update(['status' => 'past_due', 'grace_until' => now()->addDays($graceDays)->toDateString()]);
            }

            $tenant->update(['status' => self::statusFor($subscription), 'suspended_at' => null, 'suspension_type' => null, 'suspension_reason' => null]);

            PlatformAudit::record('tenant.reactivated', $tenant, $tenant->id, ['status' => $tenant->status], $by);
            PlatformAudit::toTenant($tenant, 'tenant.reactivated', $by);
        });

        return $tenant->fresh();
    }

    public function archive(Tenant $tenant, string $reason, ?PlatformAdmin $by = null): Tenant
    {
        if ($tenant->status === 'archived') {
            throw new PlatformRuleException('This institution is already archived.');
        }

        DB::transaction(function () use ($tenant, $reason, $by) {
            $tenant->subscriptions()->where('status', '!=', 'cancelled')->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $tenant->update(['status' => 'archived', 'archived_at' => now(), 'suspension_reason' => $reason]);
            PlatformAudit::record('tenant.archived', $tenant, $tenant->id, ['reason' => $reason], $by);
        });

        return $tenant->fresh();
    }

    /** Called by billing. Never overrides a manual suspension or an archive. */
    public function syncFromSubscription(Tenant $tenant, Subscription $subscription): void
    {
        if ($tenant->status === 'archived' || ($tenant->status === 'suspended' && $tenant->suspension_type === 'manual')) {
            return;
        }

        $status = self::statusFor($subscription);
        if ($status === $tenant->status) {
            return;
        }

        $updates = ['status' => $status];
        if ($status === 'suspended') {
            $updates += [
                'suspended_at' => now(), 'suspension_type' => 'billing',
                'suspension_reason' => $subscription->status === 'cancelled' ? 'Subscription cancelled' : 'Unpaid invoice past the grace period',
            ];
        } else {
            $updates += ['suspended_at' => null, 'suspension_type' => null, 'suspension_reason' => null];
        }

        $before = $tenant->status;
        $tenant->update($updates);
        PlatformAudit::record('tenant.status_synced', $tenant, $tenant->id, ['from' => $before, 'to' => $status, 'subscription' => $subscription->status]);
    }

    private static function statusFor(?Subscription $subscription): string
    {
        return match ($subscription?->status) {
            'trialing' => 'trial',
            'active' => 'active',
            'past_due' => 'past_due',
            'suspended', 'cancelled' => 'suspended',
            default => 'active',
        };
    }
}
