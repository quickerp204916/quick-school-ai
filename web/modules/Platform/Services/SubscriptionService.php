<?php

namespace Modules\Platform\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Exceptions\PlatformRuleException;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\PlatformAdmin;
use Modules\Platform\Models\Subscription;
use Modules\Platform\Models\Tenant;

final class SubscriptionService
{
    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    public static function periodEnd(CarbonInterface $start, string $cycle): CarbonImmutable
    {
        $start = CarbonImmutable::instance($start);

        return ($cycle === 'yearly' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow())->subDay();
    }

    /** Start a tenant's first subscription: a trial if the plan has one, otherwise billed at once. */
    public function start(Tenant $tenant, Plan $plan, string $cycle, ?PlatformAdmin $by = null, ?CarbonInterface $today = null): Subscription
    {
        $this->assertCycle($cycle);
        if (! $plan->is_active) {
            throw new PlatformRuleException("The {$plan->name} plan is not active.");
        }

        $today = CarbonImmutable::instance($today ?? now())->startOfDay();

        return DB::transaction(function () use ($tenant, $plan, $cycle, $by, $today) {
            if ($plan->trial_days > 0) {
                $trialEnds = $today->addDays($plan->trial_days);
                $subscription = Subscription::create([
                    'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'trialing', 'billing_cycle' => $cycle,
                    'price' => $plan->priceFor($cycle)->toDecimal(), 'trial_ends_at' => $trialEnds->toDateString(),
                    'current_period_start' => $today->toDateString(), 'current_period_end' => $trialEnds->subDay()->toDateString(),
                ]);
            } else {
                $end = self::periodEnd($today, $cycle);
                $subscription = Subscription::create([
                    'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => $cycle,
                    'price' => $plan->priceFor($cycle)->toDecimal(),
                    'current_period_start' => $today->toDateString(), 'current_period_end' => $end->toDateString(),
                ]);
                $this->invoices->issueForPeriod($subscription, $today, $end, $today);
            }

            PlatformAudit::record('subscription.started', $subscription, $tenant->id,
                ['plan' => $plan->code, 'cycle' => $cycle, 'status' => $subscription->status], $by);

            return $subscription;
        });
    }

    /** Change plan or cycle now. The new price applies from the next invoice (no proration). */
    public function changePlan(Subscription $subscription, Plan $plan, string $cycle, ?PlatformAdmin $by = null): Subscription
    {
        $this->assertCycle($cycle);
        if ($subscription->status === 'cancelled') {
            throw new PlatformRuleException('This subscription is cancelled. Start a new one instead.');
        }
        if (! $plan->is_active) {
            throw new PlatformRuleException("The {$plan->name} plan is not active.");
        }

        $before = ['plan' => $subscription->plan->code, 'cycle' => $subscription->billing_cycle, 'price' => $subscription->price];
        $subscription->update(['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'price' => $plan->priceFor($cycle)->toDecimal()]);

        PlatformAudit::record('subscription.plan_changed', $subscription, $subscription->tenant_id,
            ['before' => $before, 'after' => ['plan' => $plan->code, 'cycle' => $cycle, 'price' => $subscription->price]], $by);
        PlatformAudit::toTenant($subscription->tenant, 'subscription.plan_changed', $by, ['plan' => $plan->name, 'cycle' => $cycle]);

        return $subscription->fresh('plan');
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd, string $reason, ?PlatformAdmin $by = null): Subscription
    {
        if ($subscription->status === 'cancelled') {
            throw new PlatformRuleException('This subscription is already cancelled.');
        }

        if ($atPeriodEnd) {
            $subscription->update(['cancel_at_period_end' => true]);
        } else {
            $subscription->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            app(TenantLifecycle::class)->syncFromSubscription($subscription->tenant->fresh(), $subscription);
        }

        PlatformAudit::record('subscription.cancelled', $subscription, $subscription->tenant_id,
            ['at_period_end' => $atPeriodEnd, 'reason' => $reason], $by);

        return $subscription->fresh();
    }

    public function resume(Subscription $subscription, ?PlatformAdmin $by = null): Subscription
    {
        if (! $subscription->cancel_at_period_end || $subscription->status === 'cancelled') {
            throw new PlatformRuleException('Only a subscription set to cancel at period end can be resumed.');
        }

        $subscription->update(['cancel_at_period_end' => false]);
        PlatformAudit::record('subscription.resumed', $subscription, $subscription->tenant_id, [], $by);

        return $subscription->fresh();
    }

    private function assertCycle(string $cycle): void
    {
        if (! in_array($cycle, ['monthly', 'yearly'], true)) {
            throw new PlatformRuleException('Billing cycle must be monthly or yearly.');
        }
    }
}
