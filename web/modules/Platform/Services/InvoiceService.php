<?php

namespace Modules\Platform\Services;

use App\Core\Support\DocumentNumber;
use App\Core\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Exceptions\PlatformRuleException;
use Modules\Platform\Models\PlatformAdmin;
use Modules\Platform\Models\PlatformInvoice;
use Modules\Platform\Models\PlatformPayment;
use Modules\Platform\Models\Subscription;

final class InvoiceService
{
    /**
     * GST on a subscription: CGST + SGST (half each) when the institution is in the
     * seller's state or its state is unknown, IGST otherwise.
     *
     * @return array{cgst: Money, sgst: Money, igst: Money}
     */
    public static function taxes(Money $subtotal, ?string $buyerStateCode): array
    {
        $rate = (int) config('platform.billing.gst_rate_bp');
        $sellerState = (string) config('platform.seller.state_code');

        if ($buyerStateCode === null || $buyerStateCode === '' || $buyerStateCode === $sellerState) {
            $half = intdiv($rate, 2);

            return ['cgst' => $subtotal->percent($half), 'sgst' => $subtotal->percent($rate - $half), 'igst' => Money::zero()];
        }

        return ['cgst' => Money::zero(), 'sgst' => Money::zero(), 'igst' => $subtotal->percent($rate)];
    }

    /** Issue the invoice for one subscription period. Safe to call twice: returns the existing one. */
    public function issueForPeriod(Subscription $subscription, CarbonInterface $start, CarbonInterface $end, ?CarbonInterface $today = null): PlatformInvoice
    {
        $today = CarbonImmutable::instance($today ?? now())->startOfDay();

        return DB::transaction(function () use ($subscription, $start, $end, $today) {
            $existing = PlatformInvoice::where('subscription_id', $subscription->id)
                ->whereDate('period_start', $start->toDateString())->first();
            if ($existing) {
                return $existing;
            }

            $tenant = $subscription->tenant;
            $plan = $subscription->plan;
            $subtotal = $subscription->priceMoney();
            $tax = self::taxes($subtotal, $tenant->billing_state_code);
            $total = $subtotal->plus($tax['cgst'])->plus($tax['sgst'])->plus($tax['igst']);

            return PlatformInvoice::create([
                'number' => DocumentNumber::next((string) config('platform.billing.invoice_series'), $today),
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'issue_date' => $today->toDateString(),
                'due_date' => $today->addDays((int) config('platform.billing.due_days'))->toDateString(),
                'status' => $total->isZero() ? 'paid' : 'open',
                'currency' => 'INR',
                'description' => sprintf('Quick School AI %s plan, %s, %s to %s', $plan->name, $subscription->billing_cycle,
                    $start->format('d M Y'), $end->format('d M Y')),
                'subtotal' => $subtotal->toDecimal(),
                'cgst' => $tax['cgst']->toDecimal(),
                'sgst' => $tax['sgst']->toDecimal(),
                'igst' => $tax['igst']->toDecimal(),
                'total' => $total->toDecimal(),
                'amount_paid' => '0.00',
                'seller_gstin' => config('platform.seller.gstin') ?: null,
                'buyer_gstin' => $tenant->billing_gstin,
                'buyer_name' => $tenant->legal_name ?: $tenant->name,
                'place_of_supply' => $tenant->billing_state_code ?: config('platform.seller.state_code'),
                'sac' => config('platform.seller.sac'),
                'paid_at' => $total->isZero() ? now() : null,
            ]);
        });
    }

    /**
     * Record money received against an invoice. With an idempotency key, a retry
     * of the same request returns the payment already recorded.
     */
    public function recordPayment(PlatformInvoice $invoice, Money $amount, string $method, ?string $reference, CarbonInterface $paidOn, ?PlatformAdmin $by = null, ?string $idempotencyKey = null): PlatformPayment
    {
        if ($idempotencyKey && ($existing = PlatformPayment::where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        if (! in_array($method, PlatformPayment::METHODS, true)) {
            throw new PlatformRuleException('Unknown payment method.');
        }
        if (! $amount->isPositive()) {
            throw new PlatformRuleException('Payment amount must be more than zero.');
        }

        $payment = DB::transaction(function () use ($invoice, $amount, $method, $reference, $paidOn, $by, $idempotencyKey) {
            $locked = PlatformInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'open') {
                throw new PlatformRuleException("Invoice {$locked->number} is {$locked->status}, so it cannot take a payment.");
            }
            if (! $locked->balance()->greaterThanOrEqual($amount)) {
                throw new PlatformRuleException('Payment is more than the balance of '.$locked->balance()->format().'.');
            }

            $payment = PlatformPayment::create([
                'invoice_id' => $locked->id,
                'amount' => $amount->toDecimal(),
                'method' => $method,
                'reference' => $reference,
                'paid_on' => $paidOn->toDateString(),
                'recorded_by' => $by?->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $paid = Money::of($locked->amount_paid)->plus($amount);
            $locked->amount_paid = $paid->toDecimal();
            if ($locked->balance()->isZero()) {
                $locked->status = 'paid';
                $locked->paid_at = now();
            }
            $locked->save();

            PlatformAudit::record('invoice.payment_recorded', $locked, $locked->tenant_id,
                ['amount' => $amount->toDecimal(), 'method' => $method, 'reference' => $reference], $by);

            return $payment;
        });

        $invoice->refresh();
        if ($invoice->status === 'paid' && $invoice->subscription) {
            app(BillingRun::class)->reconcile($invoice->subscription->fresh(), now());
        }

        return $payment;
    }

    public function void(PlatformInvoice $invoice, string $reason, ?PlatformAdmin $by = null): void
    {
        if ($invoice->status !== 'open' || ! Money::of($invoice->amount_paid)->isZero()) {
            throw new PlatformRuleException('Only open invoices with no payments can be voided.');
        }

        $invoice->update(['status' => 'void']);
        PlatformAudit::record('invoice.voided', $invoice, $invoice->tenant_id, ['reason' => $reason], $by);

        if ($invoice->subscription) {
            app(BillingRun::class)->reconcile($invoice->subscription->fresh(), now());
        }
    }
}
