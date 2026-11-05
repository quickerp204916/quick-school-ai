<?php

namespace Modules\Fees\Gateways;

use Modules\Academic\Models\Student;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Models\FeePaymentOrder;
use Modules\Fees\Models\FeeRefund;

/**
 * A payment gateway. Implementations talk to the real gateway API; nothing here is simulated.
 * Payments are only recorded after the gateway's signature (webhook) or server-to-server lookup confirms them.
 */
interface PaymentGateway
{
    /** Creates the order at the gateway and returns what the app needs to open checkout. Sets $order->gateway_order_id. */
    public function createOrder(FeePaymentOrder $order, Student $student, array $customer): array;

    /** Confirms a payment for an order after checkout: ['payment_id' => ..., 'amount' => paise] or null if not paid yet. */
    public function confirm(FeePaymentOrder $order, array $input): ?array;

    /** True when the webhook body carries a valid signature for this institution's secret. */
    public function verifyWebhook(string $body, array $headers): bool;

    /** Normalised webhook: [event_id, event, kind: payment|refund|other, order_id, payment_id, amount, refund_id, success]. */
    public function parseWebhook(string $body, array $headers): array;

    /** Refunds through the gateway: ['id' => gateway refund id, 'processed' => bool]. */
    public function refund(FeePayment $payment, FeeRefund $refund): array;
}
