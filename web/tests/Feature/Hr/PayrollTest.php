<?php

namespace Tests\Feature\Hr;

use Modules\Hr\HrException;
use Modules\Hr\Models\Attendance;
use Modules\Hr\Models\PayrollRun;
use Modules\Hr\Models\SalaryStructure;
use Modules\Hr\Models\StatutoryRule;
use Modules\Hr\Services\AttendanceService;
use Modules\Hr\Services\PayrollService;

/** Payroll computation (PF, ESI, PT, TDS, overtime, loss of pay), maker-checker approval, posting, locking, bank file and payment. */
class PayrollTest extends HrTestCase
{
    public function test_pay_is_computed_from_structure_attendance_and_statutory_rules(): void
    {
        $a = $this->employee('A1', ['BASIC' => '25000', 'HRA' => '10000', 'SPECIAL' => '5000']);
        $b = $this->employee('B1', ['BASIC' => '12000', 'HRA' => '4000', 'CONV' => '2000']);
        $c = $this->employee('C1', ['BASIC' => '200000', 'HRA' => '100000', 'SPECIAL' => '100000']);
        $this->in(fn () => app(AttendanceService::class)->mark('2026-11-02', [$b->id => ['status' => 'absent'], $a->id => ['status' => 'present', 'overtime_minutes' => 120]]));
        $this->in(fn () => app(AttendanceService::class)->mark('2026-11-03', [$b->id => ['status' => 'absent']]));
        $run = $this->process();

        $sa = $this->slip($run, $a);
        $this->assertSame(['BASIC' => 2500000, 'HRA' => 1000000, 'SPECIAL' => 500000, 'OT' => 48100], $sa->earnings, 'Overtime: 2 h × 25,000 ÷ 208 × 2');
        $this->assertSame(['PF' => 180000, 'PT' => 20000], $sa->deductions, 'PF on the ₹15,000 ceiling; no ESI above ₹21,000; Maharashtra PT ₹200; no tax under the rebate');
        $this->assertSame(['EPS' => 125000, 'EPF' => 55000, 'PF' => 180000], $sa->employer);
        $this->assertSame(4048100 - 200000, $sa->net);

        $sb = $this->slip($run, $b);
        $this->assertSame([28.0, 2.0], [$sb->paid_days, $sb->lop_days]);
        $this->assertSame(['BASIC' => 1120000, 'HRA' => 373300, 'CONV' => 186700], $sb->earnings, 'Prorated for 28 of 30 days, to the rupee');
        $this->assertSame(['PF' => 134400, 'ESI' => 12600, 'PT' => 20000], $sb->deductions, 'ESI 0.75% of ₹16,800');
        $this->assertSame(54600, $sb->employer['ESI'], 'Employer ESI 3.25%');

        $sc = $this->slip($run, $c);
        // Five months left in the year: ₹20,00,000 projected − ₹75,000 standard deduction = ₹19,25,000; tax ₹1,85,000 + 4% cess = ₹1,92,400; ÷ 5.
        $this->assertSame(3848000, $sc->deductions['TDS']);
        $this->assertSame(['projected_income' => 2000000, 'taxable_income' => 1925000, 'annual_tax' => 192400], array_intersect_key($sc->snapshot['tds'], array_flip(['projected_income', 'taxable_income', 'annual_tax'])));

        $this->assertSame(['processed', 3], [$run->status, $run->employees]);
    }

    public function test_old_regime_uses_80c_and_rebate(): void
    {
        $s = $this->employee('OLD', ['BASIC' => '60000', 'HRA' => '20000'], ['tax_regime' => 'old', 'declared_80c' => '100000']);
        $sl = $this->slip($this->process(), $s);
        // ₹4,00,000 projected − ₹50,000 − 80C (₹1,00,000 declared + ₹9,000 PF) = ₹2,41,000: no tax.
        $this->assertSame(241000, $sl->snapshot['tds']['taxable_income']);
        $this->assertArrayNotHasKey('TDS', $sl->deductions);
    }

    public function test_approval_needs_another_person_posts_the_journal_and_locks_the_month(): void
    {
        $a = $this->employee('A1', ['BASIC' => '25000', 'HRA' => '10000', 'SPECIAL' => '5000']);
        $b = $this->employee('B1', ['BASIC' => '12000', 'HRA' => '4000', 'CONV' => '2000']);
        $hr = $this->asRole('hr');
        $run = $this->process();
        $this->assertThrows(fn () => $this->in(fn () => $this->payroll()->approve($run)), \Illuminate\Auth\Access\AuthorizationException::class);

        $this->asOwner();
        $owned = $this->in(fn () => $this->payroll()->process('2026-11'));   // the owner reprocesses: now the owner is the processor
        $this->assertThrows(fn () => $this->in(fn () => $this->payroll()->approve($owned)), HrException::class, 'another approver');

        $this->asRole('admin');
        $run = $this->in(fn () => $this->payroll()->approve($owned->fresh()));
        $this->assertSame('approved', $run->status);
        $net = $run->net;
        $this->assertSame(4000000 + 1800000, $this->balance('SALARY'));
        $this->assertSame(180000 + 144000, $this->balance('PF-EMPLOYER'));
        $this->assertSame(-((180000 + 144000) * 2), $this->balance('PF-PAY'), 'Employee and employer PF');
        $this->assertSame(-(13500 + 58500), $this->balance('ESI-PAY'));
        $this->assertSame(-40000, $this->balance('PT-PAY'));
        $this->assertSame(-$net, $this->balance('SAL-PAY'));
        $tb = $this->in(fn () => app(\Modules\Accounting\Services\Books::class)->trialBalance('2026-04-01', '2027-03-31'));
        $this->assertSame($tb['debit'], $tb['credit']);

        // The month is locked.
        $this->assertThrows(fn () => $this->in(fn () => $this->payroll()->process('2026-11')), HrException::class, 'already approved');
        $this->asOwner();
        $this->assertThrows(fn () => $this->in(fn () => app(AttendanceService::class)->mark('2026-11-03', [$a->id => ['status' => 'absent']])), HrException::class, 'approved');
        $this->assertThrows(fn () => $this->in(fn () => $this->slip($run, $a)->update(['net' => 1])), \LogicException::class, 'cannot be changed');
        $this->assertThrows(fn () => $this->in(fn () => SalaryStructure::where('staff_member_id', $a->id)->first()->update(['monthly_gross' => 1])), \LogicException::class, 'new effective date');
        $this->assertThrows(fn () => $this->in(fn () => app(\Modules\Hr\Services\EmployeeService::class)->revise($a, ['effective_from' => '2026-11-01', 'components' => ['BASIC' => '30000']])), HrException::class, 'approved');

        // Bank file, then payment.
        $file = $this->in(fn () => $this->payroll()->bankFile($run));
        $this->assertCount(2, $file['rows']);
        $this->assertSame(['N', 'Employee A1', '50100012345678', 'HDFC0001234'], array_slice($file['rows'][0], 0, 4));
        $this->in(fn () => $this->payroll()->markPaid($run->fresh(), 'HDFC-BATCH-1101'));
        $this->assertSame([0, -$net], [$this->balance('SAL-PAY'), $this->balance('BANK')]);
    }

    public function test_statutory_rules_are_effective_dated_and_history_is_kept(): void
    {
        $a = $this->employee('A1', ['BASIC' => '10000']);
        $this->in(fn () => $this->payroll()->addRule('pf', '2026-12-01', ['employee_bp' => 1000], 'Test change'));
        $this->assertSame(1200, $this->in(fn () => StatutoryRule::inForce('pf', '2026-11-30')->config['employee_bp']));
        $this->assertSame(1000, $this->in(fn () => StatutoryRule::inForce('pf', '2026-12-31')->config['employee_bp']));
        $this->assertSame(15_00000, $this->in(fn () => StatutoryRule::inForce('pf', '2026-12-31')->config['wage_ceiling']), 'Unchanged values carry over');
        $this->assertThrows(fn () => $this->in(fn () => StatutoryRule::inForce('pf', '2026-12-31')->update(['note' => 'x'])), \LogicException::class, 'never edited');

        $nov = $this->process();
        $this->assertSame(120000, $this->slip($nov, $a)->deductions['PF']);
        $this->asRole('admin');
        $this->in(fn () => $this->payroll()->approve($nov));
        $this->assertThrows(fn () => $this->in(fn () => $this->payroll()->addRule('pf', '2026-11-15', ['employee_bp' => 900])), HrException::class, 'approved');

        $this->travelTo('2026-12-31 10:00');
        $dec = $this->process('2026-12');
        $this->assertSame(100000, $this->slip($dec, $a)->deductions['PF']);
    }

    public function test_one_offs_and_processing_rules(): void
    {
        $a = $this->employee('A1', ['BASIC' => '20000']);
        $this->in(fn () => $this->payroll()->addInput($a, ['period' => '2026-11', 'component' => 'BONUS', 'amount' => '5000', 'note' => 'Diwali bonus']));
        $this->in(fn () => $this->payroll()->addInput($a, ['period' => '2026-11', 'component' => 'ADVANCE', 'amount' => '2000']));
        $s = $this->slip($this->process(), $a);
        $this->assertSame(500000, $s->earnings['BONUS']);
        $this->assertSame(200000, $s->deductions['ADVANCE']);
        $this->assertThrows(fn () => $this->process('2026-12'), HrException::class, 'this month or earlier');
        $this->travelTo('2026-12-31 10:00');
        $this->assertThrows(fn () => $this->process('2026-12'), HrException::class, 'Approve the November 2026 payroll first');
    }

    public function test_new_joiner_and_missing_structure(): void
    {
        $this->employee('NEW', ['BASIC' => '30000'], ['joined_on' => '2026-11-16', 'salary_from' => '2026-11-16']);
        $this->in(fn () => app(\Modules\Hr\Services\EmployeeService::class)->save(['employee_code' => 'NOSAL', 'name' => 'No salary yet']));
        $run = $this->process();
        $this->assertSame(1, $run->employees, 'Staff without a structure are left out');
        $s = $this->in(fn () => $run->payslips()->first());
        $this->assertSame([15.0, 1500000], [$s->paid_days, $s->earnings['BASIC']], 'Paid from the joining date');
    }
}
