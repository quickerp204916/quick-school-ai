<?php

namespace Tests\Feature\Fees;

use Illuminate\Support\Facades\Http;
use Modules\Academic\Models\Guardian;
use Modules\Academic\Models\Student;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Models\FeePaymentOrder;
use Modules\Fees\Models\FeeRefund;
use Modules\Fees\Models\FeeSetting;
use Modules\Fees\Models\FeeWebhookEvent;
use Modules\Identity\Models\User;

/**
 * Razorpay and Cashfree: orders, checkout verification, signed webhooks, gateway refunds; the parent and staff API; the fee screens.
 * The gateways' HTTP APIs are faked at the HTTP layer only; signatures are computed exactly as the gateways compute them.
 */
class OnlinePaymentsAndApiTest extends FeeTestCase
{
    private function gateways(): void
    {
        $this->in(fn () => FeeSetting::current()->update(['gateways' => ['razorpay', 'cashfree'], 'razorpay_key_id' => 'rzp_test_abc', 'razorpay_key_secret' => 'rzp-secret',
            'razorpay_webhook_secret' => 'rzp-hook', 'cashfree_app_id' => 'cf-app', 'cashfree_secret_key' => 'cf-secret', 'cashfree_env' => 'sandbox']));
    }

    private function token(User $user): string
    {
        $user->forceFill(['password' => 'Secret-pass-123'])->save();
        $this->app['auth']->forgetGuards();

        return $this->postJson($this->api('auth/token'), ['email' => $user->email, 'password' => 'Secret-pass-123', 'device_name' => 'Phone'])->assertCreated()->json('token');
    }

    private function parentOf(Student $student): User
    {
        $user = $this->member('parent');
        $this->in(fn () => Guardian::whereHas('students', fn ($q) => $q->whereKey($student->id))->firstOrFail()->update(['user_id' => $user->id]));

        return $user;
    }

    private function webhook(string $gateway, string $body, array $headers)
    {
        $server = collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all() + ['CONTENT_TYPE' => 'application/json'];

        return $this->call('POST', $this->api("webhooks/fees/{$gateway}"), [], [], [], $server, $body);
    }

    private function rzpPaymentBody(string $orderId, string $paymentId, int $amount): string
    {
        return json_encode(['entity' => 'event', 'event' => 'payment.captured', 'payload' => ['payment' => ['entity' =>
            ['id' => $paymentId, 'amount' => $amount, 'order_id' => $orderId, 'status' => 'captured', 'method' => 'upi']]]]);
    }

    public function test_razorpay_checkout_is_verified_and_recorded_once(): void
    {
        $this->gateways();
        $student = $this->feeStudent();
        $parent = $this->token($this->parentOf($student));
        $nov = $this->in(fn () => FeeInvoice::where('student_id', $student->id)->where('instalment', 8)->first());
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_N1', 'amount' => 300000, 'status' => 'created']),
            'api.razorpay.com/v1/payments/pay_P1' => Http::response(['id' => 'pay_P1', 'amount' => 300000, 'order_id' => 'order_N1', 'status' => 'captured', 'method' => 'upi']),
        ]);

        $o = $this->withToken($parent)->postJson($this->api('fees/orders'), ['student_id' => $student->id, 'invoice_ids' => [$nov->id], 'gateway' => 'razorpay'])
            ->assertCreated()->assertJsonPath('data.order_id', 'order_N1')->assertJsonPath('data.key', 'rzp_test_abc')->assertJsonPath('data.amount', 300000);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.razorpay.com/v1/orders' && $r['amount'] === 300000 && $r->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_abc:rzp-secret')));
        $orderId = $o->json('data.order');

        // A forged signature is refused and nothing is recorded.
        $this->withToken($parent)->postJson($this->api("fees/orders/{$orderId}/confirm"), ['razorpay_payment_id' => 'pay_P1', 'razorpay_signature' => 'forged'])->assertUnprocessable();
        $this->assertSame(0, $this->in(fn () => FeePayment::count()));

        $sig = hash_hmac('sha256', 'order_N1|pay_P1', 'rzp-secret');
        $this->withToken($parent)->postJson($this->api("fees/orders/{$orderId}/confirm"), ['razorpay_payment_id' => 'pay_P1', 'razorpay_signature' => $sig])
            ->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('data.amount', 300000)->assertJsonPath('data.allocations.0.period', 'November 2026');
        $this->assertSame('paid', $this->in(fn () => $nov->fresh()->status), 'Paid the invoice the parent chose, not the oldest');
        $this->assertSame(300000, $this->balance('GW-RAZORPAY'), 'Online money waits in the clearing ledger until settlement');

        // The webhook for the same payment arrives afterwards: recognised, not recorded again.
        $body = $this->rzpPaymentBody('order_N1', 'pay_P1', 300000);
        $this->webhook('razorpay', $body, ['X-Razorpay-Signature' => hash_hmac('sha256', $body, 'rzp-hook'), 'X-Razorpay-Event-Id' => 'evt_1'])
            ->assertOk()->assertJsonPath('result', 'already recorded');
        $this->webhook('razorpay', $body, ['X-Razorpay-Signature' => hash_hmac('sha256', $body, 'rzp-hook'), 'X-Razorpay-Event-Id' => 'evt_1'])
            ->assertOk()->assertJsonPath('result', 'duplicate');
        $this->assertSame(1, $this->in(fn () => FeePayment::count()));
        $this->trialBalanceAgrees();
    }

    public function test_razorpay_webhook_records_a_payment_and_refuses_bad_signatures(): void
    {
        $this->gateways();
        $student = $this->feeStudent();
        $order = $this->in(fn () => FeePaymentOrder::create(['student_id' => $student->id, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_W1', 'amount' => 300000,
            'invoice_ids' => [FeeInvoice::where('student_id', $student->id)->where('instalment', 2)->value('id')], 'status' => 'created']));
        $body = $this->rzpPaymentBody('order_W1', 'pay_W1', 300000);

        $this->webhook('razorpay', $body, ['X-Razorpay-Signature' => 'bad'])->assertStatus(400);
        $tampered = str_replace('300000', '900000', $body);
        $this->webhook('razorpay', $tampered, ['X-Razorpay-Signature' => hash_hmac('sha256', $body, 'rzp-hook')])->assertStatus(400);
        $this->assertSame(0, $this->in(fn () => FeePayment::count()));
        $this->assertSame(2, $this->in(fn () => FeeWebhookEvent::where('verified', false)->count()), 'Refused calls are logged');

        $this->webhook('razorpay', $body, ['X-Razorpay-Signature' => hash_hmac('sha256', $body, 'rzp-hook'), 'X-Razorpay-Event-Id' => 'evt_9'])->assertOk();
        $p = $this->in(fn () => FeePayment::sole());
        $this->assertSame(['razorpay', 'pay_W1', 300000, null], [$p->mode, $p->gateway_payment_id, $p->amount, $p->received_by]);
        $this->assertSame('paid', $this->in(fn () => $order->fresh()->status));

        // A webhook signed for another institution is refused here.
        $other = $this->provision();
        $this->call('POST', $this->api('webhooks/fees/razorpay', $other), [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'rzp-hook'), 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertStatus(400);
    }

    public function test_razorpay_refund_goes_through_the_gateway_api(): void
    {
        $this->gateways();
        $student = $this->feeStudent();
        $p = $this->in(fn () => app(\Modules\Fees\Services\OnlinePayments::class)->record(FeePaymentOrder::create(['student_id' => $student->id, 'gateway' => 'razorpay',
            'gateway_order_id' => 'order_R1', 'amount' => 300000, 'invoice_ids' => [], 'status' => 'created']), 'pay_R1', 300000));
        Http::fake(['api.razorpay.com/v1/payments/pay_R1/refund' => Http::response(['id' => 'rfnd_1', 'amount' => 100000, 'status' => 'processed'])]);

        $this->actingAs($this->member('accountant'), 'web');
        $r = $this->in(fn () => $this->collections()->requestRefund($p, ['amount' => '1000', 'reason' => 'Paid by mistake']));
        $this->asOwner();
        $this->in(fn () => $this->collections()->approveRefund($r));
        $this->in(fn () => $this->collections()->processRefund($r->fresh()));

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/payments/pay_R1/refund') && $req['amount'] === 100000);
        $r = $this->in(fn () => FeeRefund::sole());
        $this->assertSame(['processed', 'rfnd_1'], [$r->status, $r->gateway_refund_id]);
        $this->assertSame(200000, $this->balance('GW-RAZORPAY'));
        $this->trialBalanceAgrees();
    }

    public function test_gateway_errors_are_reported_and_nothing_is_posted(): void
    {
        $this->gateways();
        $student = $this->feeStudent();
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['error' => ['description' => 'Authentication failed']], 401)]);

        $this->assertThrows(fn () => $this->in(fn () => app(\Modules\Fees\Services\OnlinePayments::class)->createOrder($student, [], 'razorpay')),
            \Modules\Fees\FeeException::class, 'Authentication failed');
        $this->assertSame('failed', $this->in(fn () => FeePaymentOrder::sole()->status));
    }

    public function test_cashfree_order_and_signed_webhook_with_replay_protection(): void
    {
        $this->gateways();
        $student = $this->feeStudent();
        Http::fake(['sandbox.cashfree.com/pg/orders' => fn ($r) => Http::response(['order_id' => $r['order_id'], 'payment_session_id' => 'session_abc', 'order_status' => 'ACTIVE'])]);

        $o = $this->in(fn () => app(\Modules\Fees\Services\OnlinePayments::class)->createOrder($student, [], 'cashfree'));
        $this->assertSame(['cashfree', 'session_abc'], [$o['gateway'], $o['payment_session_id']]);
        Http::assertSent(fn ($r) => $r->hasHeader('x-client-id', 'cf-app') && $r->hasHeader('x-api-version', '2023-08-01') && $r['order_amount'] === 40000.0);

        $body = json_encode(['type' => 'PAYMENT_SUCCESS_WEBHOOK', 'event_time' => now()->toIso8601String(), 'data' => [
            'order' => ['order_id' => $o['order_id'], 'order_amount' => 40000.00], 'payment' => ['cf_payment_id' => 5114910512, 'payment_status' => 'SUCCESS', 'payment_amount' => 40000.00, 'payment_group' => 'upi']]]);
        $sign = fn (string $ts, string $b) => base64_encode(hash_hmac('sha256', $ts.$b, 'cf-secret', true));

        $old = (string) ((time() - 3600) * 1000);
        $this->webhook('cashfree', $body, ['x-webhook-signature' => $sign($old, $body), 'x-webhook-timestamp' => $old])->assertStatus(400);
        $ts = (string) (time() * 1000);
        $this->webhook('cashfree', $body, ['x-webhook-signature' => $sign($ts, $body).'x', 'x-webhook-timestamp' => $ts])->assertStatus(400);
        $this->webhook('cashfree', $body, ['x-webhook-signature' => $sign($ts, $body), 'x-webhook-timestamp' => $ts])->assertOk();

        $p = $this->in(fn () => FeePayment::sole());
        $this->assertSame(['cashfree', '5114910512', 4000000], [$p->mode, $p->gateway_payment_id, $p->amount]);
        $this->assertSame(0, $this->due($student));
        $this->assertSame(4000000, $this->balance('GW-CASHFREE'));
    }

    public function test_parents_see_and_pay_only_their_own_children(): void
    {
        $this->gateways();
        $mine = $this->feeStudent();
        $other = $this->feeStudent();
        $token = $this->token($this->parentOf($mine));

        $this->withToken($token)->getJson($this->api("fees/students/{$mine->id}"))->assertOk()
            ->assertJsonPath('balance', 4000000)->assertJsonCount(8, 'invoices')->assertJsonCount(4, 'upcoming')->assertJsonPath('gateways', ['razorpay', 'cashfree']);
        $this->withToken($token)->getJson($this->api("fees/students/{$other->id}"))->assertForbidden();
        $this->withToken($token)->postJson($this->api('fees/orders'), ['student_id' => $other->id, 'gateway' => 'razorpay'])->assertForbidden();
        $this->withToken($token)->postJson($this->api("fees/students/{$mine->id}/payments"), ['amount' => '100', 'mode' => 'cash'])->assertForbidden();
        $this->withToken($token)->getJson($this->api('fees/outstanding'))->assertForbidden();
    }

    public function test_staff_api_collection_is_idempotent_and_receipt_link_is_signed(): void
    {
        $student = $this->feeStudent();
        $t = $this->token($this->member('accountant'));
        $a = $this->withToken($t)->withHeader('Idempotency-Key', 'tab-77')->postJson($this->api("fees/students/{$student->id}/payments"), ['amount' => '19000', 'mode' => 'cash'])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'false')->assertJsonPath('data.amount', 1900000);
        $this->withToken($t)->withHeader('Idempotency-Key', 'tab-77')->postJson($this->api("fees/students/{$student->id}/payments"), ['amount' => '19000', 'mode' => 'cash'])
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $a->json('data.id'));
        $this->withoutHeader('Idempotency-Key')->withToken($t)->postJson($this->api("fees/students/{$student->id}/payments"), ['amount' => '19000', 'mode' => 'cash'])->assertUnprocessable();
        $this->withToken($t)->getJson($this->api('fees/outstanding'))->assertOk()->assertJsonPath('data.0.total', 2100000);

        $url = $a->json('data.receipt_url');
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertSee($a->json('data.number'))->assertSee('Nineteen Thousand');
        $this->get($url.'x')->assertForbidden();
    }

    public function test_fee_screens_render(): void
    {
        $student = $this->feeStudent(['concession_ids' => [$this->concession()->id]]);
        $p = $this->pay($student, '25000', ['mode' => 'cheque', 'reference' => 'SBI 1234']);
        $this->in(fn () => $this->collections()->requestRefund($p, ['amount' => '100', 'reason' => 'Rounding difference']));
        $inv = $this->invoices($student)->first();
        $s = $this->in(fn () => \Modules\Fees\Models\FeeStructure::first());

        foreach (['fees' => 'Collected today', 'fees/setup' => 'Fee heads', 'fees/structures' => 'Structure ', 'fees/structures/create' => 'New fee structure',
            "fees/structures/{$s->id}/edit" => 'Instalments a full-year student gets', 'fees/students' => $student->fullName(), "fees/students/{$student->id}" => 'Collect a payment',
            'fees/invoices' => 'Fee invoices', "fees/invoices/{$inv->id}" => 'April 2026', 'fees/payments?from=2026-11-01' => $p->number, "fees/payments/{$p->id}" => 'Cheque returned',
            'fees/approvals' => 'Rounding difference', 'fees/reports/outstanding' => $student->fullName(), 'fees/reports/collections' => 'By day', 'fees/reports/concessions' => 'Sibling'] as $path => $text) {
            $this->get($this->at($path))->assertOk()->assertSee($text, false);
        }
        $this->get($this->at('fees/reports/outstanding?format=csv'))->assertOk()->assertDownload();

        $this->actingAs($this->member('teacher'), 'web');
        $this->get($this->at('fees'))->assertForbidden();
        $this->actingAs($this->member('front_office'), 'web');
        $this->get($this->at("fees/students/{$student->id}"))->assertOk();
        $this->get($this->at('fees/setup'))->assertForbidden();
    }

    public function test_counter_form_posts_a_receipt_once(): void
    {
        $student = $this->feeStudent();
        $form = ['amount' => '19000', 'mode' => 'cash', 'date' => '2026-11-04', 'idempotency_key' => 'web:abc'];
        $this->post($this->at("fees/students/{$student->id}/payments"), $form)->assertRedirect();
        $this->post($this->at("fees/students/{$student->id}/payments"), $form)->assertRedirect();
        $this->assertSame(1, $this->in(fn () => FeePayment::count()));
        $this->post($this->at("fees/students/{$student->id}/payments"), ['amount' => '', 'mode' => 'cash', 'idempotency_key' => 'web:x'])->assertSessionHasErrors();
    }

    public function test_gateway_settlements_clear_the_clearing_ledger_once(): void
    {
        $this->gateways();
        $body = json_encode(['entity' => 'event', 'event' => 'settlement.processed', 'payload' => ['settlement' => ['entity' =>
            ['id' => 'setl_9A1', 'amount' => 980000, 'fees' => 23600, 'tax' => 3600, 'status' => 'processed', 'utr' => 'UTR123', 'created_at' => strtotime('2026-11-04 10:00')]]]]);
        $this->webhook('razorpay', $body, ['X-Razorpay-Signature' => 'bad', 'X-Razorpay-Event-Id' => 'evt_s1'])->assertStatus(400);
        $sig = hash_hmac('sha256', $body, 'rzp-hook');
        $this->webhook('razorpay', $body, ['X-Razorpay-Signature' => $sig, 'X-Razorpay-Event-Id' => 'evt_s1'])->assertOk();
        $this->webhook('razorpay', $body, ['X-Razorpay-Signature' => $sig, 'X-Razorpay-Event-Id' => 'evt_s1'])->assertOk();   // retried by the gateway

        // Gross ₹10,036 = net ₹9,800 + fee ₹200 + GST ₹36.
        $this->assertSame([-1003600, 980000, 20000, 3600], [$this->balance('GW-RAZORPAY'), $this->balance('BANK'), $this->balance('GW-CHARGES'), $this->balance('GST-IN-IGST')]);
        $this->assertSame(1, $this->in(fn () => \Modules\Accounting\Models\Voucher::where('source_type', 'gateway_settlement')->count()));

        // Cashfree (or a Razorpay settlement entered by hand) from the Receipts screen; a second submission posts nothing new.
        $form = ['gateway' => 'cashfree', 'settlement_id' => 'CF-SET-77', 'date' => '2026-11-04', 'gross' => '5000', 'fee' => '95', 'gst' => '17.10'];
        $this->post($this->at('fees/settlements'), $form)->assertSessionHasNoErrors()->assertRedirect();
        $this->post($this->at('fees/settlements'), $form)->assertSessionHasNoErrors();
        $this->post($this->at('fees/settlements'), ['gross' => '100', 'fee' => '100'] + $form)->assertSessionHasErrors();
        $this->assertSame([-500000, 980000 + 488790], [$this->balance('GW-CASHFREE'), $this->balance('BANK')]);
        $this->actingAs($this->member('accountant'), 'web');
        $this->post($this->at('fees/settlements'), ['settlement_id' => 'CF-SET-78'] + $form)->assertForbidden();
        $this->trialBalanceAgrees();
    }
}
