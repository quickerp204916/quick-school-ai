<?php

namespace Tests\Feature\Platform;

use App\Core\Support\Money;
use Carbon\CarbonImmutable;
use Modules\Platform\Exceptions\PlatformRuleException;
use Modules\Platform\Models\PlatformInvoice;
use Modules\Platform\Services\InvoiceService;

class InvoiceTest extends PlatformTestCase
{
    private function issued(string $state = '09'): PlatformInvoice
    {
        $plan = $this->plan('starter');
        $plan->update(['trial_days' => 0]);

        return $this->provision(['plan_id' => $plan->id, 'billing_state_code' => $state])->invoices()->firstOrFail();
    }

    public function test_same_state_splits_cgst_and_sgst(): void
    {
        $inv = $this->issued('09');

        $this->assertSame('2999.00', $inv->subtotal);
        $this->assertSame('269.91', $inv->cgst);
        $this->assertSame('269.91', $inv->sgst);
        $this->assertSame('0.00', $inv->igst);
        $this->assertSame('3538.82', $inv->total);
        $this->assertSame('2026-11-11', $inv->due_date->toDateString());
    }

    public function test_other_state_charges_igst(): void
    {
        $inv = $this->issued('27');

        $this->assertSame('0.00', $inv->cgst);
        $this->assertSame('539.82', $inv->igst);
        $this->assertSame('3538.82', $inv->total);
        $this->assertSame('27', $inv->place_of_supply);
    }

    public function test_numbers_are_sequential_per_financial_year(): void
    {
        $first = $this->issued();
        $second = $this->issued();

        $this->assertSame('QSA/2026-27/00001', $first->number);
        $this->assertSame('QSA/2026-27/00002', $second->number);
    }

    public function test_issuing_the_same_period_twice_returns_the_same_invoice(): void
    {
        $inv = $this->issued();
        $again = app(InvoiceService::class)->issueForPeriod($inv->subscription, CarbonImmutable::parse($inv->period_start), CarbonImmutable::parse($inv->period_end));

        $this->assertSame($inv->id, $again->id);
        $this->assertSame(1, PlatformInvoice::count());
    }

    public function test_payments_settle_the_invoice_and_refuse_overpayment(): void
    {
        $inv = $this->issued();
        $service = app(InvoiceService::class);

        $service->recordPayment($inv, Money::of('1000'), 'upi', 'UTR1', now());
        $this->assertSame('open', $inv->fresh()->status);
        $this->assertSame('2538.82', $inv->fresh()->balance()->toDecimal());

        try {
            $service->recordPayment($inv, Money::of('3000'), 'upi', 'UTR2', now());
            $this->fail('Overpayment was accepted.');
        } catch (PlatformRuleException) {
        }

        $service->recordPayment($inv, Money::of('2538.82'), 'bank_transfer', 'NEFT9', now());
        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertNotNull($inv->fresh()->paid_at);
    }

    public function test_payment_with_the_same_idempotency_key_is_recorded_once(): void
    {
        $inv = $this->issued();
        $service = app(InvoiceService::class);

        $p1 = $service->recordPayment($inv, Money::of('500'), 'upi', 'UTR1', now(), null, 'retry-key-1');
        $p2 = $service->recordPayment($inv, Money::of('500'), 'upi', 'UTR1', now(), null, 'retry-key-1');

        $this->assertSame($p1->id, $p2->id);
        $this->assertSame('500.00', $inv->fresh()->amount_paid);
    }

    public function test_console_payment_and_void(): void
    {
        $inv = $this->issued();
        $billing = $this->admin('billing');

        $this->signIn($billing)->post(route('platform.invoices.payments.store', $inv), [
            'amount' => '3538.82', 'method' => 'bank_transfer', 'reference' => 'NEFT123', 'paid_on' => '2026-11-04', 'form_token' => 'abc',
        ])->assertSessionHasNoErrors();
        $this->assertSame('paid', $inv->fresh()->status);

        $other = $this->issued();
        $this->signIn($billing)->post(route('platform.invoices.void', $other), ['reason' => 'Issued in error'])->assertSessionHasNoErrors();
        $this->assertSame('void', $other->fresh()->status);
    }
}
