<?php

namespace Modules\Fees\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Academic\Models\Student;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Models\FeePaymentOrder;
use Modules\Fees\Models\FeeRefund;
use Modules\Fees\Models\FeeSetting;

/**
 * Razorpay Orders API (https://razorpay.com/docs/api/orders/).
 * Checkout returns razorpay_payment_id, razorpay_order_id and razorpay_signature = HMAC-SHA256(order_id|payment_id, key_secret).
 * Webhooks carry X-Razorpay-Signature = HMAC-SHA256(raw body, webhook secret).
 */
final class Razorpay implements PaymentGateway
{
    public function __construct(private readonly FeeSetting $s)
    {
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('fees.razorpay.api'))->withBasicAuth($this->s->razorpay_key_id, $this->s->razorpay_key_secret)
            ->acceptJson()->timeout(20);   // no automatic retry: a repeated POST could create a second order or refund
    }

    private function ok($res): array
    {
        if ($res->failed()) {
            throw new GatewayException($res->json('error.description') ?? 'Razorpay returned HTTP '.$res->status());
        }

        return $res->json();
    }

    public function createOrder(FeePaymentOrder $order, Student $student, array $customer): array
    {
        $res = $this->ok($this->http()->post('orders', ['amount' => $order->amount, 'currency' => 'INR', 'receipt' => substr($order->id, 0, 40),
            'notes' => ['order' => $order->id, 'student' => $student->admission_no, 'tenant' => $order->tenant_id]]));
        $order->gateway_order_id = $res['id'];

        return ['gateway' => 'razorpay', 'key' => $this->s->razorpay_key_id, 'order_id' => $res['id'], 'amount' => $order->amount, 'currency' => 'INR',
            'name' => $customer['institution'] ?? 'School fees', 'description' => 'Fees for '.$student->fullName(),
            'prefill' => ['name' => $customer['name'] ?? null, 'email' => $customer['email'] ?? null, 'contact' => $customer['phone'] ?? null]];
    }

    public function confirm(FeePaymentOrder $order, array $input): ?array
    {
        $paymentId = (string) ($input['razorpay_payment_id'] ?? '');
        $sig = (string) ($input['razorpay_signature'] ?? '');
        if ($paymentId === '' || ! hash_equals(hash_hmac('sha256', $order->gateway_order_id.'|'.$paymentId, (string) $this->s->razorpay_key_secret), $sig)) {
            throw new GatewayException('The payment signature does not match. The payment was not recorded.');
        }
        // Server-to-server check of the amount and status, so a tampered client can't record a different payment.
        $p = $this->ok($this->http()->get("payments/{$paymentId}"));
        if (($p['order_id'] ?? null) !== $order->gateway_order_id) {
            throw new GatewayException('This payment belongs to a different order.');
        }

        return ($p['status'] ?? '') === 'captured' ? ['payment_id' => $paymentId, 'amount' => (int) $p['amount'], 'method' => $p['method'] ?? null] : null;
    }

    public function verifyWebhook(string $body, array $headers): bool
    {
        $sig = (string) ($headers['x-razorpay-signature'] ?? '');

        return $sig !== '' && filled($this->s->razorpay_webhook_secret) && hash_equals(hash_hmac('sha256', $body, (string) $this->s->razorpay_webhook_secret), $sig);
    }

    public function parseWebhook(string $body, array $headers): array
    {
        $j = json_decode($body, true) ?: [];
        $event = (string) ($j['event'] ?? '');
        $pay = $j['payload']['payment']['entity'] ?? [];
        $ref = $j['payload']['refund']['entity'] ?? [];
        $kind = in_array($event, ['payment.captured', 'order.paid'], true) ? 'payment' : (str_starts_with($event, 'refund.') ? 'refund' : 'other');
        if ($event === 'settlement.processed') {
            // Razorpay settles the net amount; "fees" includes the GST on fees, which "tax" gives separately.
            $set = $j['payload']['settlement']['entity'] ?? [];

            return ['event_id' => (string) ($headers['x-razorpay-event-id'] ?? hash('sha256', $body)), 'event' => $event, 'kind' => 'settlement', 'success' => ($set['status'] ?? '') === 'processed',
                'settlement' => ['id' => (string) ($set['id'] ?? ''), 'net' => (int) ($set['amount'] ?? 0), 'fee' => (int) ($set['fees'] ?? 0) - (int) ($set['tax'] ?? 0), 'tax' => (int) ($set['tax'] ?? 0),
                    'date' => isset($set['created_at']) ? date('Y-m-d', (int) $set['created_at']) : today()->format('Y-m-d'), 'utr' => $set['utr'] ?? null]];
        }

        return ['event_id' => (string) ($headers['x-razorpay-event-id'] ?? hash('sha256', $body)), 'event' => $event, 'kind' => $kind,
            'order_id' => $pay['order_id'] ?? null, 'payment_id' => $pay['id'] ?? ($ref['payment_id'] ?? null), 'amount' => (int) ($pay['amount'] ?? $ref['amount'] ?? 0),
            'refund_id' => $ref['id'] ?? null, 'success' => $kind === 'payment' ? ($pay['status'] ?? '') === 'captured' : $event === 'refund.processed'];
    }

    public function refund(FeePayment $payment, FeeRefund $refund): array
    {
        $res = $this->ok($this->http()->post("payments/{$payment->gateway_payment_id}/refund", ['amount' => $refund->amount, 'receipt' => substr($refund->id, 0, 40),
            'notes' => ['refund' => $refund->id, 'reason' => mb_substr($refund->reason, 0, 200)]]));

        return ['id' => $res['id'], 'processed' => ($res['status'] ?? '') === 'processed'];
    }
}
