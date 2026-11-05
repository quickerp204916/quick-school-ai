<?php

namespace Modules\Platform\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Models\PlatformInvoice;
use Modules\Platform\Models\Subscription;

/**
 * Daily billing job (php artisan platform:billing-run). Every step is idempotent,
 * so running it twice on the same day changes nothing the second time.
 *
 * 1. Trials that ended become active and get their first invoice.
 * 2. Active subscriptions past their period end renew (or end, if cancelling) with a new invoice.
 * 3. Dunning: overdue -> past_due; overdue past the grace period -> suspended; paid -> active.
 * 4. Monthly AI credits are granted once per calendar month.
 */
final class BillingRun
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly TenantLifecycle $lifecycle,
        private readonly AiCredits $credits,
    ) {
    }

    /** @return array{trials_converted: int, renewed: int, cancelled: int, past_due: int, suspended: int, reactivated: int, credits_granted: int} */
    public function run(?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::instance($today ?? now())->startOfDay();
        $summary = ['trials_converted' => 0, 'renewed' => 0, 'cancelled' => 0, 'past_due' => 0, 'suspended' => 0, 'reactivated' => 0, 'credits_granted' => 0];

        // 1. Trials that have ended.
        Subscription::where('status', 'trialing')->whereDate('trial_ends_at', '<=', $today->toDateString())
            ->with(['tenant', 'plan'])->lazyById(200)->each(function (Subscription $s) use ($today, &$summary) {
                DB::transaction(function () use ($s, $today) {
                    $start = CarbonImmutable::instance($s->trial_ends_at);
                    $end = SubscriptionService::periodEnd($start, $s->billing_cycle);
                    $s->update(['status' => 'active', 'current_period_start' => $start->toDateString(), 'current_period_end' => $end->toDateString()]);
                    $this->invoices->issueForPeriod($s, $start, $end, $today);
                    $this->lifecycle->syncFromSubscription($s->tenant, $s);
                });
                $summary['trials_converted']++;
            });

        // 2. Renewals and scheduled cancellations.
        Subscription::whereIn('status', ['active', 'past_due'])->whereDate('current_period_end', '<', $today->toDateString())
            ->with(['tenant', 'plan'])->lazyById(200)->each(function (Subscription $s) use ($today, &$summary) {
                if ($s->cancel_at_period_end) {
                    $s->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                    $this->lifecycle->syncFromSubscription($s->tenant, $s);
                    $summary['cancelled']++;

                    return;
                }

                DB::transaction(function () use ($s, $today) {
                    $start = CarbonImmutable::instance($s->current_period_end)->addDay();
                    $end = SubscriptionService::periodEnd($start, $s->billing_cycle);
                    $s->update(['current_period_start' => $start->toDateString(), 'current_period_end' => $end->toDateString()]);
                    $this->invoices->issueForPeriod($s, $start, $end, $today);
                });
                $summary['renewed']++;
            });

        // 3. Dunning.
        Subscription::whereIn('status', ['active', 'past_due', 'suspended'])->with('tenant')
            ->lazyById(200)->each(function (Subscription $s) use ($today, &$summary) {
                $before = $s->status;
                $this->reconcile($s, $today);
                if ($s->status !== $before) {
                    $key = ['past_due' => 'past_due', 'suspended' => 'suspended', 'active' => 'reactivated'][$s->status] ?? null;
                    if ($key) {
                        $summary[$key]++;
                    }
                }
            });

        // 4. Monthly AI credits.
        $month = $today->format('Y-m');
        Subscription::whereIn('status', ['trialing', 'active', 'past_due'])->with('tenant')
            ->lazyById(200)->each(function (Subscription $s) use ($month, &$summary) {
                $amount = FeatureGate::for($s->tenant)->limit('ai_credits_monthly');
                if ($amount !== null && $amount > 0 && $this->credits->grantOnce($s->tenant, $amount, 'monthly_grant', $month, "Monthly credits for {$month}")) {
                    $summary['credits_granted']++;
                }
            });

        return $summary;
    }

    /** Set a subscription's status from its oldest unpaid invoice, then update the tenant. */
    public function reconcile(Subscription $subscription, CarbonInterface $today): void
    {
        if (! in_array($subscription->status, ['active', 'past_due', 'suspended'], true)) {
            return;
        }

        $today = CarbonImmutable::instance($today)->startOfDay();
        $oldestOpen = PlatformInvoice::where('subscription_id', $subscription->id)->where('status', 'open')
            ->orderBy('due_date')->first();

        $status = 'active';
        if ($oldestOpen && $oldestOpen->due_date->lt($today)) {
            $graceEnds = CarbonImmutable::instance($oldestOpen->due_date)->addDays((int) config('platform.billing.suspend_after_days'));
            $status = $today->gt($graceEnds) ? 'suspended' : 'past_due';
        }

        // Staff lifted a billing suspension by hand: stay past due until the grace date.
        if ($status === 'suspended' && $subscription->grace_until && $today->lte($subscription->grace_until)) {
            $status = 'past_due';
        }

        $updates = [];
        if ($status !== $subscription->status) {
            $updates['status'] = $status;
        }
        if ($status === 'active' && $subscription->grace_until) {
            $updates['grace_until'] = null;
        }
        if ($updates) {
            $subscription->update($updates);
            if (isset($updates['status'])) {
                PlatformAudit::record('subscription.status_changed', $subscription, $subscription->tenant_id, ['to' => $status]);
            }
        }

        $this->lifecycle->syncFromSubscription($subscription->tenant->fresh(), $subscription);
    }
}
