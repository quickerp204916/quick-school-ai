<?php

namespace Tests\Feature\Platform;

use App\Core\Support\Money;
use Carbon\Carbon;
use Modules\Platform\Services\AiCredits;
use Modules\Platform\Services\BillingRun;
use Modules\Platform\Services\InvoiceService;
use Modules\Platform\Services\SubscriptionService;
use Modules\Platform\Services\TenantLifecycle;

class BillingRunTest extends PlatformTestCase
{
    private function runOn(string $date): array
    {
        $this->travelTo(Carbon::parse($date.' 02:15:00'));

        return app(BillingRun::class)->run(now());
    }

    public function test_trial_end_starts_billing_once(): void
    {
        $tenant = $this->provision();   // 14-day trial from 4 Nov

        $this->assertSame(0, $this->runOn('2026-11-17')['trials_converted']);
        $this->assertSame(1, $this->runOn('2026-11-18')['trials_converted']);
        $this->assertSame(0, $this->runOn('2026-11-18')['trials_converted']);

        $s = $tenant->subscription()->first();
        $this->assertSame('active', $s->status);
        $this->assertSame('2026-11-18', $s->current_period_start->toDateString());
        $this->assertSame('2026-12-17', $s->current_period_end->toDateString());
        $this->assertSame(1, $tenant->invoices()->count());
        $this->assertSame('3538.82', $tenant->invoices()->first()->total);
        $this->assertSame('active', $tenant->fresh()->status);
    }

    public function test_dunning_moves_to_past_due_then_suspends_then_payment_restores(): void
    {
        $tenant = $this->provision();
        $this->runOn('2026-11-18');                         // invoice due 25 Nov

        $this->runOn('2026-11-26');
        $this->assertSame('past_due', $tenant->fresh()->status);

        $this->runOn('2026-12-09');                         // 14 days after due date: still in grace
        $this->assertSame('past_due', $tenant->fresh()->status);

        $this->runOn('2026-12-10');
        $tenant->refresh();
        $this->assertSame('suspended', $tenant->status);
        $this->assertSame('billing', $tenant->suspension_type);
        $this->assertFalse($tenant->isAccessible());

        $invoice = $tenant->invoices()->first();
        app(InvoiceService::class)->recordPayment($invoice, Money::of($invoice->total), 'upi', 'UTR77', now());

        $this->assertSame('active', $tenant->fresh()->status);
        $this->assertSame('active', $tenant->subscription()->first()->status);
    }

    public function test_payment_never_lifts_a_manual_suspension(): void
    {
        $tenant = $this->provision();
        $this->runOn('2026-11-18');
        app(TenantLifecycle::class)->suspend($tenant, 'Contract dispute');

        $invoice = $tenant->invoices()->first();
        app(InvoiceService::class)->recordPayment($invoice, Money::of($invoice->total), 'upi', 'UTR1', now());

        $this->assertSame('suspended', $tenant->fresh()->status);
        $this->assertSame('manual', $tenant->fresh()->suspension_type);
    }

    public function test_manual_reactivation_gives_a_grace_period(): void
    {
        $tenant = $this->provision();
        $this->runOn('2026-11-18');
        $this->runOn('2026-12-10');
        $this->assertSame('suspended', $tenant->fresh()->status);

        app(TenantLifecycle::class)->reactivate($tenant->fresh());
        $this->assertSame('past_due', $tenant->fresh()->status);

        $this->runOn('2026-12-11');
        $this->assertSame('past_due', $tenant->fresh()->status);   // grace until 17 Dec

        $this->runOn('2026-12-18');
        $this->assertSame('suspended', $tenant->fresh()->status);
    }

    public function test_renewal_issues_the_next_invoice(): void
    {
        $tenant = $this->provision();
        $this->runOn('2026-11-18');
        $invoice = $tenant->invoices()->first();
        app(InvoiceService::class)->recordPayment($invoice, Money::of($invoice->total), 'upi', 'UTR1', now());

        $this->assertSame(1, $this->runOn('2026-12-18')['renewed']);
        $this->assertSame(2, $tenant->invoices()->count());
        $this->assertSame('2027-01-17', $tenant->subscription()->first()->current_period_end->toDateString());
        $this->assertSame(0, $this->runOn('2026-12-18')['renewed']);
    }

    public function test_cancel_at_period_end_stops_at_the_end(): void
    {
        $tenant = $this->provision();
        $this->runOn('2026-11-18');
        $invoice = $tenant->invoices()->first();
        app(InvoiceService::class)->recordPayment($invoice, Money::of($invoice->total), 'upi', 'UTR1', now());
        app(SubscriptionService::class)->cancel($tenant->subscription()->first(), true, 'Moving to another vendor');

        $this->assertSame(1, $this->runOn('2026-12-18')['cancelled']);
        $tenant->refresh();
        $this->assertSame('suspended', $tenant->status);
        $this->assertSame('Subscription cancelled', $tenant->suspension_reason);
        $this->assertSame(1, $tenant->invoices()->count());
    }

    public function test_monthly_ai_credits_are_granted_once_per_month(): void
    {
        $tenant = $this->provision(['plan_id' => $this->plan('growth')->id]);   // 2,000 credits a month
        $credits = app(AiCredits::class);

        $this->assertSame(1, $this->runOn('2026-11-05')['credits_granted']);
        $this->assertSame(0, $this->runOn('2026-11-06')['credits_granted']);
        $this->assertSame(2000, $credits->balance($tenant));

        $credits->consume($tenant, 150, 'chat-1');
        $this->runOn('2026-12-01');
        $this->assertSame(3850, $credits->balance($tenant));
    }

    public function test_starter_plan_gets_no_ai_credits(): void
    {
        $tenant = $this->provision();
        $this->runOn('2026-11-05');

        $this->assertSame(0, app(AiCredits::class)->balance($tenant));
    }
}
