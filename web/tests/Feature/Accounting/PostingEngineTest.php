<?php

namespace Tests\Feature\Accounting;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Models\AccountGroup;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\FinancialYear;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Services\LedgerService;

class PostingEngineTest extends AccountingTestCase
{
    public function test_onboarding_creates_the_books(): void
    {
        $this->in(function () {
            $this->assertSame('2026-27', FinancialYear::current()->name);
            $this->assertSame(31, AccountGroup::count());
            $this->assertNotNull(AccountingSetting::current()->approval_thresholds);
        });
        $this->assertSame('cash', $this->ledger('CASH')->type);
    }

    public function test_debits_must_equal_credits(): void
    {
        $this->expectExceptionMessage('Debits (₹1,000.00) and credits (₹900.00) must be equal. Difference: ₹100.00.');
        $this->postVoucher('payment', [['ELECTRICITY', '1000'], ['BANK', '', '900']]);
    }

    public function test_each_line_is_one_sided_and_positive(): void
    {
        foreach ([[['RENT', '100', '100'], ['BANK', '', '100']], [['RENT', '-100'], ['BANK', '', '-100']], [['RENT', '0'], ['BANK', '', '0']]] as $lines) {
            try {
                $this->postVoucher('payment', $lines);
                $this->fail('Accepted '.json_encode($lines));
            } catch (AccountingException $e) {
                $this->assertStringContainsString('', $e->getMessage());
            }
        }
        $this->assertSame(0, $this->in(fn () => LedgerEntry::count()));
    }

    public function test_voucher_types_use_the_right_ledgers(): void
    {
        $cases = [
            ['payment', [['RENT', '500'], ['FEE-TUITION', '', '500']], 'credit a cash or bank ledger'],
            ['receipt', [['RENT', '500'], ['FEE-TUITION', '', '500']], 'debit a cash or bank ledger'],
            ['contra', [['BANK', '500'], ['RENT', '', '500']], 'only between cash and bank'],
            ['journal', [['RENT', '500'], ['CASH', '', '500']], 'cannot use cash or bank'],
        ];
        foreach ($cases as [$type, $lines, $msg]) {
            try {
                $this->postVoucher($type, $lines);
                $this->fail("{$type} accepted");
            } catch (AccountingException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        $this->postVoucher('contra', [['BANK', '5000'], ['CASH', '', '5000']]);
        $this->assertSame(500000, $this->balance('BANK'));
    }

    public function test_posting_numbers_are_gapless_per_type_and_year(): void
    {
        $a = $this->postVoucher('payment', [['ELECTRICITY', '1200'], ['BANK', '', '1200']]);
        $b = $this->postVoucher('receipt', [['CASH', '300'], ['MISC-INCOME', '', '300']]);
        $c = $this->postVoucher('payment', [['REPAIRS', '800'], ['CASH', '', '800']]);
        $this->assertSame(['PV/2026-27/00001', 'RV/2026-27/00001', 'PV/2026-27/00002'], [$a->number, $b->number, $c->number]);
        $this->assertSame(64, strlen($a->entries_hash));
    }

    public function test_large_payments_need_a_second_persons_approval(): void
    {
        $accountant = $this->member('accountant');
        $this->actingAs($accountant, 'web');
        $v = $this->postVoucher('payment', [['RENT', '30000'], ['BANK', '', '30000']]);
        $this->assertSame(['pending', null], [$v->status, $v->number]);
        $this->assertSame(0, $this->balance('RENT'));

        // The person who entered it cannot approve it, and an accountant cannot approve at all.
        $this->expectApprovalRefused($v, 'Your role cannot do this.');
        $this->asOwner();
        $owner = $this->ownerUser();
        $v->forceFill(['created_by' => $owner->id])->saveQuietly();
        $this->expectApprovalRefused($v, 'someone else must approve it');
        $v->forceFill(['created_by' => $accountant->id])->saveQuietly();

        $posted = $this->in(fn () => $this->vouchers()->approve($v));
        $this->assertSame(['posted', 'PV/2026-27/00001', $owner->id], [$posted->status, $posted->number, $posted->approved_by]);
        $this->assertSame(3000000, $this->balance('RENT'));
    }

    private function expectApprovalRefused(Voucher $v, string $msg): void
    {
        try {
            $this->in(fn () => $this->vouchers()->approve($v));
            $this->fail('Approval allowed');
        } catch (\Throwable $e) {
            $this->assertStringContainsString($msg, $e->getMessage());
        }
    }

    public function test_rejected_vouchers_go_back_for_correction(): void
    {
        $this->actingAs($this->member('accountant'), 'web');
        $v = $this->postVoucher('journal', [['RENT', '100'], ['MISC-INCOME', '', '100']]);   // journals always need approval
        $this->asOwner();
        $this->in(fn () => $this->vouchers()->reject($v, 'Wrong ledger, use repairs'));
        $f = $this->in(fn () => $v->fresh());
        $this->assertSame(['rejected', 'Wrong ledger, use repairs'], [$f->status, $f->rejected_reason]);
        $this->assertSame(0, $this->in(fn () => LedgerEntry::count()));
    }

    public function test_posted_entries_and_vouchers_cannot_change(): void
    {
        $v = $this->postVoucher('payment', [['ELECTRICITY', '1200'], ['BANK', '', '1200']]);
        $entry = $this->in(fn () => LedgerEntry::firstOrFail());

        foreach ([fn () => $entry->update(['debit' => 1]), fn () => $entry->delete(), fn () => $v->update(['narration' => 'changed']), fn () => $v->delete()] as $attempt) {
            try {
                $this->in($attempt);
                $this->fail('Change allowed');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('', $e->getMessage());
            }
        }
    }

    public function test_the_database_refuses_raw_changes_to_posted_entries(): void
    {
        $this->requireDatabaseGuard();
        $this->postVoucher('payment', [['ELECTRICITY', '1200'], ['BANK', '', '1200']]);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Posted ledger entries cannot be changed');
        $this->in(fn () => DB::table('ledger_entries')->update(['debit' => 1]));
    }

    public function test_corrections_are_reversals(): void
    {
        $v = $this->postVoucher('payment', [['ELECTRICITY', '1200'], ['BANK', '', '1200']]);
        $rev = $this->in(fn () => $this->vouchers()->reverse($v, 'Paid from the wrong account'));

        $f = $this->in(fn () => $v->fresh());
        $this->assertSame(['reversal', 'RE/2026-27/00001', 'reversed', $rev->id], [$rev->type, $rev->number, $f->status, $f->reversed_by_id]);
        $v = $f;
        $this->assertSame([0, 0], [$this->balance('ELECTRICITY'), $this->balance('BANK')]);
        $this->assertSame(4, $this->in(fn () => LedgerEntry::count()), 'The original entries stay; the reversal adds opposite ones');

        foreach ([[$v, 'already been reversed'], [$rev, 'cannot itself be reversed']] as [$target, $msg]) {
            try {
                $this->in(fn () => $this->vouchers()->reverse($target, 'Again please'));
                $this->fail('Reversed twice');
            } catch (AccountingException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
    }

    public function test_locked_periods_refuse_postings_and_only_the_owner_unlocks(): void
    {
        $old = $this->postVoucher('payment', [['ELECTRICITY', '500'], ['BANK', '', '500']], '2026-10-10');
        $this->in(fn () => app(LedgerService::class)->lockUntil('2026-10-31'));

        foreach ([fn () => $this->postVoucher('payment', [['ELECTRICITY', '500'], ['BANK', '', '500']], '2026-10-20'),
                  fn () => $this->in(fn () => $this->vouchers()->reverse($old, 'Wrong amount', '2026-10-25'))] as $attempt) {
            try {
                $attempt();
                $this->fail('Posted into a locked period');
            } catch (AccountingException $e) {
                $this->assertStringContainsString('locked up to 31 Oct 2026', $e->getMessage());
            }
        }
        // Reversing today is fine: the correction lands in the open period.
        $this->in(fn () => $this->vouchers()->reverse($old, 'Wrong amount'));

        $this->actingAs($this->member('admin'), 'web');
        $this->assertThrows(fn () => $this->in(fn () => app(LedgerService::class)->lockUntil('2026-09-30')), AccountingException::class, 'Only the owner');
        $this->asOwner();
        $this->assertThrows(fn () => $this->in(fn () => app(LedgerService::class)->lockUntil(null)), AccountingException::class, 'reason');
        $this->in(fn () => app(LedgerService::class)->lockUntil(null, 'Auditor asked for an adjustment'));
        $this->postVoucher('payment', [['ELECTRICITY', '500'], ['BANK', '', '500']], '2026-10-20');
    }

    public function test_dates_outside_any_financial_year_and_closed_years(): void
    {
        $this->assertThrows(fn () => $this->postVoucher('payment', [['RENT', '1'], ['BANK', '', '1']], '2028-05-01'), AccountingException::class, 'no financial year');
        $this->travelTo('2027-04-05');
        $this->in(fn () => app(LedgerService::class)->closeYear(FinancialYear::where('name', '2026-27')->first()));
        $this->assertThrows(fn () => $this->postVoucher('payment', [['RENT', '1'], ['BANK', '', '1']], '2027-03-15'), AccountingException::class, 'is closed');
        $v = $this->postVoucher('payment', [['RENT', '1'], ['BANK', '', '1']], '2027-04-02');
        $this->assertSame('PV/2027-28/00001', $v->number);
    }

    public function test_a_year_with_pending_vouchers_cannot_close(): void
    {
        $this->actingAs($this->member('accountant'), 'web');
        $this->postVoucher('journal', [['RENT', '100'], ['MISC-INCOME', '', '100']]);
        $this->asOwner();
        $this->travelTo('2027-04-05');
        $this->assertThrows(fn () => $this->in(fn () => app(LedgerService::class)->closeYear(FinancialYear::where('name', '2026-27')->first())), AccountingException::class, 'waiting for approval');
    }

    public function test_automatic_postings_happen_once(): void
    {
        $rec = fn (int $amt) => $this->in(fn () => $this->vouchers()->record('receipt', '2026-11-03', [['key' => 'cash', 'debit' => $amt], ['key' => 'fee_receivable', 'credit' => $amt]], 'fee_payment', 'PAY-42'));
        $a = $rec(250000);
        $b = $rec(250000);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(250000, $this->balance('CASH'));
        $this->assertThrows(fn () => $rec(260000), AccountingException::class, 'Already posted as RV/2026-27/00001 with a different amount');
    }

    public function test_ledgers_of_another_institution_are_refused(): void
    {
        $theirs = $this->ledger('RENT');
        $mine = $this->provision();
        $this->actingAs($this->ownerUser($mine), 'web');
        $this->assertThrows(fn () => $this->in(fn () => $this->vouchers()->saveDraft(['type' => 'payment', 'date' => '2026-11-02', 'lines' => [
            ['ledger_id' => $theirs->id, 'debit' => '100'], ['ledger_id' => \Modules\Accounting\Models\Ledger::system('bank_main')->id, 'credit' => '100']]]), $mine), AccountingException::class, 'choose a ledger');
    }
}
