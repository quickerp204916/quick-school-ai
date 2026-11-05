<?php

namespace Modules\Fees\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Academic\Models\Student;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Fees\FeeException;
use Modules\Fees\Gateways\Cashfree;
use Modules\Fees\Gateways\GatewayException;
use Modules\Fees\Gateways\PaymentGateway;
use Modules\Fees\Gateways\Razorpay;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Models\FeePaymentOrder;
use Modules\Fees\Models\FeeRefund;
use Modules\Fees\Models\FeeSetting;
use Modules\Fees\Models\FeeWebhookEvent;

/**
 * Online fee payment (parent app or a staff-sent link): order → gateway checkout → verified confirmation.
 * A payment is recorded only from a valid checkout signature plus a server-side lookup, or from a signed webhook,
 * and always once, keyed on the gateway's payment id.
 */
final class OnlinePayments
{
    public function __construct(private readonly CurrentMember $member, private readonly CollectionService $collections, private readonly FeePlanService $plans)
    {
    }

    public function gateway(string $name): PaymentGateway
    {
        $s = FeeSetting::current();

        return match ($name) {
            'razorpay' => new Razorpay($s),
            'cashfree' => new Cashfree($s),
            default => throw new FeeException('Unknown payment gateway.'),
        };
    }

    /** Starts an online payment for open invoices of one student. Parents may pay only for their own children. */
    public function createOrder(Student $student, array $invoiceIds, string $gateway): array
    {
        $this->member->mustSeeStudent($student);
        if (! FeeSetting::current()->enabled($gateway)) {
            throw new FeeException('Online payment through '.ucfirst($gateway).' is not set up for this institution.', 'gateway');
        }
        $this->plans->applyLateFees($student->id);
        $invoices = FeeInvoice::where('student_id', $student->id)->whereIn('status', ['open', 'partial'])
            ->when($invoiceIds, fn ($q) => $q->whereIn('id', $invoiceIds))->get();
        if ($invoices->isEmpty() || ($invoiceIds && $invoices->count() !== count(array_unique($invoiceIds)))) {
            throw new FeeException('Choose open invoices of this student to pay.', 'invoice_ids');
        }
        $amount = $invoices->sum(fn ($i) => $i->balance());
        $order = FeePaymentOrder::create(['student_id' => $student->id, 'gateway' => $gateway, 'amount' => $amount, 'invoice_ids' => $invoices->pluck('id')->all(),
            'status' => 'created', 'created_by' => $this->member->userId()]);
        $g = $student->primaryGuardian();
        try {
            $checkout = $this->gateway($gateway)->createOrder($order, $student, ['name' => $g?->name, 'email' => $g?->email, 'phone' => $g?->phone,
                'institution' => \Modules\Platform\Models\Tenant::find(\App\Core\Tenancy\TenantContext::id())?->name ?? config('app.name'), 'notify_url' => \Modules\Fees\Support::webhookUrl($gateway)]);
        } catch (GatewayException $e) {
            $order->update(['status' => 'failed']);
            throw new FeeException('Could not start the payment: '.$e->getMessage());
        }
        $order->save();
        Audit::record('fees.order_created', $order, ['amount' => $amount, 'gateway' => $gateway]);

        return ['order' => $order->id] + $checkout;
    }

    /** After checkout: verifies with the gateway and records the payment. Returns the receipt, or null if the gateway has not confirmed yet. */
    public function confirm(FeePaymentOrder $order, array $input): ?FeePayment
    {
        $this->member->mustSeeStudent($order->student);
        try {
            $paid = $this->gateway($order->gateway)->confirm($order, $input);
        } catch (GatewayException $e) {
            throw new FeeException($e->getMessage());
        }

        return $paid ? $this->record($order, $paid['payment_id'], $paid['amount']) : null;
    }

    /** Records a confirmed gateway payment once. Money received beyond the order is kept as an advance. */
    public function record(FeePaymentOrder $order, string $paymentId, int $amount): FeePayment
    {
        return DB::transaction(function () use ($order, $paymentId, $amount) {
            $order = FeePaymentOrder::lockForUpdate()->find($order->id);
            $p = $this->collections->collect($order->student, ['idempotency_key' => "gw:{$order->gateway}:{$paymentId}", 'amount' => $amount, 'mode' => $order->gateway,
                'date' => today()->format('Y-m-d'), 'reference' => $paymentId, 'gateway_payment_id' => $paymentId, 'order_id' => $order->id,
                'invoice_ids' => $order->invoice_ids], system: true);
            $order->update(['status' => 'paid']);

            return $p;
        });
    }

    /**
     * A gateway webhook for this institution. Unsigned or wrongly signed calls are logged and refused.
     * Returns a short result for the log; the controller always answers quickly so the gateway does not retry needlessly.
     */
    public function webhook(string $gateway, string $body, array $headers): array
    {
        $g = $this->gateway($gateway);
        $headers = array_change_key_case(array_map(fn ($v) => is_array($v) ? ($v[0] ?? '') : $v, $headers), CASE_LOWER);
        $verified = $g->verifyWebhook($body, $headers);
        $e = $g->parseWebhook($body, $headers);
        $event = FeeWebhookEvent::firstOrNew(['gateway' => $gateway, 'event_id' => mb_substr($e['event_id'], 0, 120)]);
        if ($event->exists && $event->verified) {
            return ['status' => 200, 'result' => 'duplicate'];
        }
        $event->fill(['event' => mb_substr($e['event'], 0, 60), 'verified' => $verified, 'payload' => json_decode($body, true) ?: ['raw' => mb_substr($body, 0, 2000)]]);
        if (! $verified) {
            $event->fill(['result' => 'signature invalid'])->save();
            Log::warning('Fee webhook with an invalid signature', ['gateway' => $gateway, 'event' => $e['event']]);

            return ['status' => 400, 'result' => 'invalid signature'];
        }
        try {
            $result = match (true) {
                $e['kind'] === 'payment' && $e['success'] => $this->paymentEvent($gateway, $e),
                $e['kind'] === 'refund' => $this->refundEvent($gateway, $e),
                $e['kind'] === 'settlement' && $e['success'] => $this->settle($gateway, $e['settlement']['id'], $e['settlement']['date'],
                    $e['settlement']['net'] + $e['settlement']['fee'] + $e['settlement']['tax'], $e['settlement']['fee'], $e['settlement']['tax'], system: true)->number,
                default => 'ignored',
            };
        } catch (\Throwable $ex) {
            $event->fill(['result' => 'error: '.mb_substr($ex->getMessage(), 0, 280)])->save();
            report($ex);

            return ['status' => 500, 'result' => 'error'];   // the gateway retries later
        }
        $event->fill(['result' => $result])->save();

        return ['status' => 200, 'result' => $result];
    }

    private function paymentEvent(string $gateway, array $e): string
    {
        $order = FeePaymentOrder::where('gateway', $gateway)->where('gateway_order_id', $e['order_id'])->first();
        if (! $order) {
            return 'order not found';
        }
        if (FeePayment::where('mode', $gateway)->where('gateway_payment_id', $e['payment_id'])->exists()) {
            return 'already recorded';
        }

        return 'recorded '.$this->record($order, (string) $e['payment_id'], $e['amount'])->number;
    }

    private function refundEvent(string $gateway, array $e): string
    {
        $r = FeeRefund::where('mode', $gateway)->where(fn ($q) => $q->where('gateway_refund_id', $e['refund_id'])->orWhere('id', $e['refund_id']))->first();
        if (! $r) {
            return 'refund not found';
        }
        if (! $e['success']) {
            $r->status === 'approved' && $r->update(['failure' => 'Gateway reported: '.$e['event']]);

            return 'refund not successful';
        }
        $this->collections->refundPaid($r);

        return 'refund confirmed';
    }

    /**
     * A gateway settlement into the bank: gross collections leave the gateway's clearing ledger, the bank gets the net,
     * and the gateway's fee and the GST on it are expensed. Posted once per settlement id (webhook or the form under Receipts).
     */
    public function settle(string $gateway, string $settlementId, string $date, int $gross, int $fee, int $gst, bool $system = false): \Modules\Accounting\Models\Voucher
    {
        if (! $system) {
            $this->member->authorize('fees.approve');
        }
        if (! in_array($gateway, ['razorpay', 'cashfree'], true) || trim($settlementId) === '') {
            throw new FeeException('Choose the gateway and enter its settlement id.', 'settlement_id');
        }
        if ($gross <= 0 || $fee < 0 || $gst < 0 || $fee + $gst >= $gross) {
            throw new FeeException('The gross amount must be more than the gateway fee plus GST.', 'gross');
        }
        $v = app(\Modules\Accounting\Services\AccountingGateway::class)->gatewaySettlement($gateway, mb_substr(trim($settlementId), 0, 40), $date, $gross, $fee, $gst);
        Audit::record('fees.settlement_posted', $v, ['gateway' => $gateway, 'settlement' => $settlementId, 'gross' => $gross]);

        return $v;
    }
}
