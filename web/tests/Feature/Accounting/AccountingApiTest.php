<?php

namespace Tests\Feature\Accounting;

use Modules\Accounting\Models\Voucher;
use Modules\Identity\Models\User;

/** Accounting JSON API: token access, permissions, idempotent posting, approvals, reversals and reports. */
class AccountingApiTest extends AccountingTestCase
{
    private function token(User $user): string
    {
        $user->forceFill(['password' => 'Secret-pass-123'])->save();

        return $this->postJson($this->api('auth/token'), ['email' => $user->email, 'password' => 'Secret-pass-123', 'device_name' => 'API'])->assertCreated()->json('token');
    }

    private function payload(string $amount = '1500.50'): array
    {
        return ['type' => 'payment', 'date' => '2026-11-02', 'narration' => 'Electricity bill', 'lines' => [
            ['ledger_id' => $this->ledger('ELECTRICITY')->id, 'debit' => $amount, 'credit' => ''],
            ['ledger_id' => $this->ledger('BANK')->id, 'debit' => '', 'credit' => $amount],
        ]];
    }

    public function test_post_a_voucher_once_with_an_idempotency_key(): void
    {
        $t = $this->token($this->ownerUser());
        $first = $this->withToken($t)->withHeader('Idempotency-Key', 'k-1')->postJson($this->api('accounting/vouchers'), $this->payload())
            ->assertCreated()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.total', 150050);
        $this->assertMatchesRegularExpression('#^PV/\d{4}-\d{2}/\d{5}$#', $first->json('data.number'));
        $this->assertCount(2, $first->json('data.lines'));

        $this->withToken($t)->withHeader('Idempotency-Key', 'k-1')->postJson($this->api('accounting/vouchers'), $this->payload())
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, $this->in(fn () => Voucher::count()));
        $this->assertSame(150050, $this->balance('ELECTRICITY'));
    }

    public function test_unbalanced_vouchers_are_rejected(): void
    {
        $p = $this->payload();
        $p['lines'][1]['credit'] = '1000';
        $this->withToken($this->token($this->ownerUser()))->postJson($this->api('accounting/vouchers'), $p)->assertUnprocessable();
        $this->assertSame(0, $this->in(fn () => Voucher::count()));
    }

    public function test_permissions(): void
    {
        $teacher = $this->token($this->member('teacher'));
        $this->withToken($teacher)->getJson($this->api('accounting/ledgers'))->assertForbidden();
        $this->withToken($teacher)->postJson($this->api('accounting/vouchers'), $this->payload())->assertForbidden();

        $this->app['auth']->forgetGuards(); // tests reuse the app between requests; drop the cached token user
        $accountant = $this->token($this->member('accountant'));
        $this->withToken($accountant)->getJson($this->api('accounting/ledgers'))->assertOk()->assertJsonFragment(['code' => 'BANK']);
        $v = $this->withToken($accountant)->postJson($this->api('accounting/vouchers'), $this->payload('900000'))->assertCreated();
        // Above the approval threshold: it waits for an approver, and an accountant cannot approve.
        $v->assertJsonPath('data.status', 'pending');
        $id = $v->json('data.id');
        $this->withToken($accountant)->postJson($this->api("accounting/vouchers/{$id}/approve"))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($this->ownerUser()))->postJson($this->api("accounting/vouchers/{$id}/approve"))->assertOk()->assertJsonPath('data.status', 'posted');
    }

    public function test_reverse_and_reports(): void
    {
        $t = $this->token($this->ownerUser());
        $id = $this->withToken($t)->postJson($this->api('accounting/vouchers'), $this->payload('2000'))->assertCreated()->json('data.id');
        $this->withToken($t)->getJson($this->api('accounting/reports/profit-loss?from=2026-04-01&to=2026-11-30'))->assertOk()->assertJsonPath('total_expense', 200000);
        $tb = $this->withToken($t)->getJson($this->api('accounting/reports/trial-balance?from=2026-04-01&to=2026-11-30'))->assertOk();
        $this->assertGreaterThan(0, $tb->json('debit'));
        $this->assertSame($tb->json('debit'), $tb->json('credit'));
        $this->withToken($t)->getJson($this->api('accounting/reports/balance-sheet?to=2026-11-30'))->assertOk()->assertJsonPath('difference', 0);

        $this->withToken($t)->postJson($this->api("accounting/vouchers/{$id}/reverse"), ['reason' => 'Wrong bill'])->assertCreated()->assertJsonPath('data.type', 'reversal');
        $this->withToken($t)->getJson($this->api("accounting/vouchers/{$id}"))->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertSame(0, $this->balance('ELECTRICITY'));
        $this->withToken($t)->getJson($this->api('accounting/reports/unknown'))->assertNotFound();
    }

    public function test_purchase_invoice_is_posted_once_per_key(): void
    {
        $vendor = $this->in(fn () => app(\Modules\Accounting\Services\LedgerService::class)->saveLedger(['code' => 'PRINTER', 'name' => 'Printer Co', 'type' => 'vendor',
            'group_id' => \Modules\Accounting\Models\AccountGroup::where('code', 'L11')->value('id')]));
        $body = ['kind' => 'purchase', 'party_ledger_id' => $vendor->id, 'date' => '2026-11-03', 'party_invoice_no' => 'P-77',
            'lines' => [['ledger_id' => $this->ledger('REPAIRS')->id, 'description' => 'Repairs', 'quantity' => 1, 'rate' => '5000', 'gst_bp' => 1800]]];
        $t = $this->token($this->ownerUser());
        $a = $this->withToken($t)->withHeader('Idempotency-Key', 'inv-1')->postJson($this->api('accounting/invoices'), $body)
            ->assertCreated()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.total', 590000);
        $this->withToken($t)->withHeader('Idempotency-Key', 'inv-1')->postJson($this->api('accounting/invoices'), $body)
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $a->json('data.id'));
        $this->assertSame(-590000, $this->balance('PRINTER'));
    }
}
