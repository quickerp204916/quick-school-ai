<?php

namespace Tests\Feature\Ai;

use Modules\Accounting\Models\Voucher;
use Modules\Ai\AiException;
use Modules\Ai\Models\AiActionRequest;
use Modules\Ai\Services\Proposals;
use Modules\Fees\Models\FeeRefund;

/** Sensitive actions run only after a different, authorised person approves them, through the module's own rules. */
class ProposalsTest extends AiTestCase
{
    private function propose(string $agent, string $tool, array $args, $user): AiActionRequest
    {
        $this->tool($tool, $args, $this->chatSession($user, $agent))->assertOk();

        return $this->in(fn () => AiActionRequest::latest()->firstOrFail());
    }

    private function proposals(): Proposals
    {
        return app(Proposals::class);
    }

    public function test_an_approved_voucher_is_posted_with_the_asker_as_maker(): void
    {
        $accountant = $this->member('accountant');
        $req = $this->propose('accounting', 'accounting.propose_voucher', ['type' => 'payment', 'date' => '2026-11-04', 'narration' => 'Roof repair', 'reference' => 'INV-77',
            'lines' => [['ledger' => 'REPAIRS', 'debit' => '40000'], ['ledger' => 'BANK', 'credit' => '40000']]], $accountant);

        // The person who asked can't approve, and an accountant can't approve vouchers at all.
        $this->actingAs($accountant, 'web');
        $this->assertThrows(fn () => $this->in(fn () => $this->proposals()->approve($req)), \Illuminate\Auth\Access\AuthorizationException::class);
        $this->assertSame(0, $this->in(fn () => Voucher::count()));

        $this->asOwner();
        $this->in(fn () => $this->proposals()->approve($req));
        $req = $this->in(fn () => $req->fresh());
        $v = $this->in(fn () => Voucher::firstOrFail());
        $this->assertSame(['approved', 'posted', $accountant->id, $this->ownerUser()->id], [$req->status, $v->status, $v->created_by, $v->approved_by]);
        $this->assertSame('Posted as '.$v->number.'.', $req->result);
        $this->assertSame(4000000, $this->balance('REPAIRS'));
        $this->assertThrows(fn () => $this->in(fn () => $this->proposals()->approve($req)), AiException::class, 'already been decided');
    }

    public function test_nobody_approves_their_own_request(): void
    {
        $req = $this->propose('accounting', 'accounting.propose_voucher', ['type' => 'journal', 'narration' => 'Reclassify', 'lines' => [['ledger' => 'STATIONERY', 'debit' => '500'], ['ledger' => 'REPAIRS', 'credit' => '500']]], $this->ownerUser());
        $this->asOwner();
        $this->assertThrows(fn () => $this->in(fn () => $this->proposals()->approve($req)), AiException::class, 'someone else');

        $this->actingAs($this->member('admin'), 'web');
        $this->assertThrows(fn () => $this->in(fn () => $this->proposals()->reject($req, '')), AiException::class, 'reason');
        $this->in(fn () => $this->proposals()->reject($req, 'Not needed'));
        $this->assertSame(['rejected', 0], [$this->in(fn () => $req->fresh()->status), $this->in(fn () => Voucher::count())]);
    }

    public function test_a_refund_goes_through_fee_maker_checker(): void
    {
        $s = $this->feeStudent();
        $this->in(fn () => $this->plans()->issueDue(null, '2026-11-04'));
        $p = $this->pay($s, '5000');
        $accountant = $this->member('accountant');
        $this->tool('fees.propose_refund', ['receipt' => $p->number, 'amount' => '9000', 'reason' => 'Paid twice'], $this->chatSession($accountant, 'accounting'))
            ->assertUnprocessable();
        $req = $this->propose('accounting', 'fees.propose_refund', ['receipt' => $p->number, 'amount' => '1000', 'reason' => 'Transport not used in November'], $accountant);
        $this->assertSame(0, $this->in(fn () => FeeRefund::count()), 'Nothing happens until approval');

        $this->asOwner();
        $this->in(fn () => $this->proposals()->approve($req));
        $r = $this->in(fn () => FeeRefund::firstOrFail());
        $this->assertSame(['approved', 100000, $accountant->id], [$r->status, $r->amount, $r->requested_by]);
        $this->trialBalanceAgrees();
    }

    public function test_a_waiver_is_applied_once_on_approval(): void
    {
        $s = $this->feeStudent();
        $this->in(fn () => $this->plans()->issueDue(null, '2026-11-04'));
        $inv = $this->invoices($s)->first();
        $before = $this->due($s);
        $req = $this->propose('accounting', 'fees.propose_waiver', ['invoice' => $inv->number, 'amount' => '500', 'reason' => 'Hardship, approved by principal'], $this->member('accountant'));
        $this->actingAs($this->member('admin'), 'web');
        $this->in(fn () => $this->proposals()->approve($req));
        $this->assertSame($before - 50000, $this->due($s));
        $this->trialBalanceAgrees();
    }

    public function test_an_approval_that_breaks_a_rule_is_recorded_as_failed(): void
    {
        $req = $this->propose('accounting', 'accounting.propose_voucher', ['type' => 'payment', 'date' => '2026-11-04', 'narration' => 'Old bill',
            'lines' => [['ledger' => 'REPAIRS', 'debit' => '100'], ['ledger' => 'CASH', 'credit' => '100']]], $this->member('accountant'));
        $this->asOwner();
        $this->in(fn () => app(\Modules\Accounting\Services\LedgerService::class)->lockUntil('2026-11-04'));
        $this->assertThrows(fn () => $this->in(fn () => $this->proposals()->approve($req)), AiException::class, 'refused');
        $this->assertSame('failed', $this->in(fn () => $req->fresh()->status));
        $this->assertSame(0, $this->in(fn () => Voucher::count()));
    }
}
