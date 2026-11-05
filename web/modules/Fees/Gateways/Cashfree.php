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
 * Cashfree Payment Gateway API, version 2023-08-01 (https://docs.cashfree.com/reference/pg-new-apis-endpoint).
 * Webhooks carry x-webhook-signature = base64(HMAC-SHA256(x-webhook-timestamp + raw body, secret key)).
 * Amounts at Cashfree are rupees with two decimals; here they are converted to and from paise.
 */
final class Cashfree implements PaymentGateway
{
    public function __construct(private readonly FeeSetting $s)
    {
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('fees.cashfree.api')[$this->s->cashfree_env === 'production' ? 'production' : 'sandbox'])
            ->withHeaders(['x-client-id' => $this->s->cashfree_app_id, 'x-client-secret' => $this->s->cashfree_secret_key, 'x-api-version' => config('fees.cashfree.version')])
            ->acceptJson()->timeout(20);   // no automatic retry: a repeated POST could create a second order or refund
    }

    private function ok($res): array
    {
        if ($res->failed()) {
            throw new GatewayException($res->json('message') ?? 'Cashfree returned HTTP '.$res->status());
        }

        return $res->json() ?? [];
    }

    private static function rupees(int $paise): float
    {
        return round($paise / 100, 2);
    }

    private static function paise(mixed $rupees): int
    {
        return (int) round(((float) $rupees) * 100);
    }

    public function createOrder(FeePaymentOrder $order, Student $student, array $customer): array
    {
        $phone = preg_replace('/\D/', '', (string) ($customer['phone'] ?? ''));
        if (strlen($phone) < 10) {
            throw new GatewayException('Cashfree needs the parent\'s 10-digit mobile number. Add it to the guardian\'s record.');
        }
        $res = $this->ok($this->http()->post('orders', ['order_id' => $order->id, 'order_amount' => self::rupees($order->amount), 'order_currency' => 'INR',
            'customer_details' => ['customer_id' => $student->id, 'customer_name' => $customer['name'] ?? $student->fullName(), 'customer_phone' => substr($phone, -10),
                'customer_email' => $customer['email'] ?? null],
            'order_meta' => ['notify_url' => $customer['notify_url'] ?? null, 'return_url' => $customer['return_url'] ?? null],
            'order_note' => 'Fees for '.$student->fullName()]));
        $order->gateway_order_id = $res['order_id'] ?? $order->id;
        $order->payment_session_id = $res['payment_session_id'] ?? null;

        return ['gateway' => 'cashfree', 'order_id' => $order->gateway_order_id, 'payment_session_id' => $order->payment_session_id, 'amount' => $order->amount,
            'mode' => $this->s->cashfree_env === 'production' ? 'production' : 'sandbox'];
    }

    public function confirm(FeePaymentOrder $order, array $input): ?array
    {
        // Never trust the redirect: ask Cashfree which payment succeeded for this order.
        $payments = $this->ok($this->http()->get("orders/{$order->gateway_order_id}/payments"));
        foreach ($payments as $p) {
            if (($p['payment_status'] ?? '') === 'SUCCESS') {
                return ['payment_id' => (string) $p['cf_payment_id'], 'amount' => self::paise($p['payment_amount']), 'method' => $p['payment_group'] ?? null];
            }
        }

        return null;
    }

    public function verifyWebhook(string $body, array $headers): bool
    {
        $sig = (string) ($headers['x-webhook-signature'] ?? '');
        $ts = (string) ($headers['x-webhook-timestamp'] ?? '');
        if ($sig === '' || $ts === '' || blank($this->s->cashfree_secret_key)) {
            return false;
        }
        $seconds = strlen($ts) > 11 ? intdiv((int) $ts, 1000) : (int) $ts;   // Cashfree sends milliseconds
        if (abs(time() - $seconds) > config('fees.cashfree.webhook_tolerance')) {
            return false;   // replayed or badly delayed
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $ts.$body, (string) $this->s->cashfree_secret_key, true)), $sig);
    }

    public function parseWebhook(string $body, array $headers): array
    {
        $j = json_decode($body, true) ?: [];
        $type = (string) ($j['type'] ?? '');
        $d = $j['data'] ?? [];
        $kind = str_starts_with($type, 'PAYMENT_') ? 'payment' : (str_starts_with($type, 'REFUND_') ? 'refund' : 'other');

        return ['event_id' => (string) ($headers['x-webhook-id'] ?? hash('sha256', $body)), 'event' => $type, 'kind' => $kind,
            'order_id' => $d['order']['order_id'] ?? ($d['refund']['order_id'] ?? null), 'payment_id' => isset($d['payment']['cf_payment_id']) ? (string) $d['payment']['cf_payment_id'] : null,
            'amount' => self::paise($d['payment']['payment_amount'] ?? ($d['refund']['refund_amount'] ?? 0)),
            'refund_id' => $d['refund']['refund_id'] ?? null,
            'success' => $kind === 'payment' ? ($d['payment']['payment_status'] ?? '') === 'SUCCESS' : ($d['refund']['refund_status'] ?? '') === 'SUCCESS'];
    }

    public function refund(FeePayment $payment, FeeRefund $refund): array
    {
        $orderId = $payment->order_id ? \Modules\Fees\Models\FeePaymentOrder::find($payment->order_id)?->gateway_order_id : null;
        if (! $orderId) {
            throw new GatewayException('The Cashfree order for this payment is not known.');
        }
        $res = $this->ok($this->http()->post("orders/{$orderId}/refunds", ['refund_amount' => self::rupees($refund->amount), 'refund_id' => $refund->id,
            'refund_note' => mb_substr($refund->reason, 0, 100)]));

        // Our refund_id is the gateway's refund id, so webhooks find it directly.
        return ['id' => $res['refund_id'] ?? $refund->id, 'processed' => ($res['refund_status'] ?? '') === 'SUCCESS'];
    }
}
