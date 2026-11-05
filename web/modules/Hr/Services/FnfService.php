<?php

namespace Modules\Hr\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Accounting\Services\AccountingGateway;
use Modules\Accounting\Support\Amount;
use Modules\Fees\Support as Fy;
use Modules\Hr\HrException;
use Modules\Hr\Models\EmployeeProfile;
use Modules\Hr\Models\FnfSettlement;
use Modules\Hr\Models\LeaveType;
use Modules\Hr\Models\Payslip;
use Modules\Hr\Models\SalaryStructure;
use Modules\Hr\Models\StatutoryRule;

/**
 * Full and final settlement for a relieved employee: unpaid salary of the last month, encashment of encashable
 * leave, gratuity (5 years or more), less notice-pay shortfall and recoveries. Prepared by HR, approved by
 * someone else, which posts it to the books; paid with a separate payment voucher.
 */
final class FnfService
{
    public function __construct(private readonly CurrentMember $member, private readonly AttendanceService $attendance, private readonly LeaveService $leave, private readonly AccountingGateway $books)
    {
    }

    public function prepare(StaffMember $staff, array $d = []): FnfSettlement
    {
        $this->member->authorize('payroll.process');
        $p = EmployeeProfile::where('staff_member_id', $staff->id)->first();
        if (! $p?->relieved_on) {
            throw new HrException('Record the relieving date first.');
        }
        $existing = FnfSettlement::where('staff_member_id', $staff->id)->first();
        if ($existing && $existing->status !== 'draft') {
            throw new HrException('This settlement is already approved.');
        }
        $on = $p->relieved_on->format('Y-m-d');
        $s = SalaryStructure::inForce($staff->id, $on) ?? throw new HrException("{$staff->name} has no salary structure.");
        $basicDa = ($s->components['BASIC'] ?? 0) + ($s->components['DA'] ?? 0);

        // Salary for days of the last month not covered by an approved payslip.
        $period = substr($on, 0, 7);
        $covered = Payslip::where('staff_member_id', $staff->id)->whereHas('run', fn ($q) => $q->where('period', $period)->whereIn('status', ['approved', 'paid']))->exists();
        $att = $this->attendance->summary($staff, $period);
        $days = $covered ? 0.0 : max(0.0, $att['payable_days'] - $att['lop_days']);
        $salary = PayrollEngine::rupee($s->monthly_gross * $days / $att['days_in_month']);

        // Encashable leave balance at (basic + DA) / 26 a day.
        $encashDays = 0.0;
        foreach (LeaveType::where('encashable', true)->get() as $t) {
            $encashDays += max(0, $this->leave->balance($staff, $t, Fy::fy($on)));
        }
        $encash = PayrollEngine::rupee($basicDa / 26 * $encashDays);

        // Gratuity: 15 days' (basic + DA) for every completed year; six months or more counts as a year.
        $g = StatutoryRule::inForce('gratuity', $on)?->config ?? config('hr.statutory.gratuity.1');
        $joined = $staff->joined_on ? CarbonImmutable::parse($staff->joined_on) : null;
        $years = $joined ? $joined->diffInMonths(CarbonImmutable::parse($on)) / 12 : 0;
        $gratYears = (int) floor($years) + (($years - floor($years)) >= 0.5 ? 1 : 0);
        $gratuity = $years >= $g['min_years'] ? min($g['cap'], PayrollEngine::rupee($basicDa * $g['days_per_year'] / $g['divisor_days'] * $gratYears)) : 0;

        // Notice shortfall: days of notice not served, at gross / days in month.
        $served = $p->resigned_on ? CarbonImmutable::parse($p->resigned_on)->diffInDays(CarbonImmutable::parse($on)) : $p->notice_days;
        $short = max(0, $p->notice_days - $served);
        $notice = array_key_exists('notice_recovery', $d) ? Amount::parse($d['notice_recovery']) : PayrollEngine::rupee($s->monthly_gross / 30 * $short);
        $advance = Amount::parse($d['advance_recovery'] ?? 0);
        $other = Amount::parse($d['other_deductions'] ?? 0);
        $tds = Amount::parse($d['tds'] ?? 0);
        $net = $salary + $encash + $gratuity - $notice - $advance - $other - $tds;

        $f = FnfSettlement::updateOrCreate(['staff_member_id' => $staff->id], ['relieved_on' => $on, 'salary_days' => $days, 'salary' => $salary,
            'encash_days' => $encashDays, 'leave_encashment' => $encash, 'service_years' => round($years, 2), 'gratuity' => $gratuity,
            'notice_recovery' => $notice, 'advance_recovery' => $advance, 'other_deductions' => $other, 'tds' => $tds, 'net' => $net, 'status' => 'draft',
            'prepared_by' => $this->member->userId(), 'workings' => ['basic_da' => $basicDa, 'monthly_gross' => $s->monthly_gross, 'notice_shortfall_days' => $short,
                'gratuity_years' => $gratYears, 'last_month_covered_by_payroll' => $covered, 'note' => $d['note'] ?? null]]);
        Audit::record('payroll.fnf_prepared', $f, ['net' => $net]);

        return $f;
    }

    public function approve(FnfSettlement $f): FnfSettlement
    {
        $this->member->authorize('payroll.approve');
        if ($f->status !== 'draft') {
            throw new HrException('This settlement is already approved.');
        }
        if ($f->prepared_by === $this->member->userId()) {
            throw new HrException('You prepared this settlement, so another approver has to approve it.');
        }

        return DB::transaction(function () use ($f) {
            $lines = array_values(array_filter([
                $f->salary ? ['key' => 'salary_expense', 'debit' => $f->salary] : null,
                $f->leave_encashment ? ['key' => 'leave_encashment', 'debit' => $f->leave_encashment] : null,
                $f->gratuity ? ['key' => 'gratuity_expense', 'debit' => $f->gratuity] : null,
                $f->notice_recovery ? ['key' => 'notice_recovery', 'credit' => $f->notice_recovery] : null,
                $f->advance_recovery ? ['key' => 'staff_advance', 'credit' => $f->advance_recovery] : null,
                $f->tds ? ['key' => 'tds_payable', 'credit' => $f->tds] : null,
            ], fn ($l) => $l && (($l['debit'] ?? 0) + ($l['credit'] ?? 0)) > 0));
            if ($f->other_deductions) {
                $lines[] = ['ledger_id' => \Modules\Accounting\Models\Ledger::where('code', 'MISC-INCOME')->value('id'), 'credit' => $f->other_deductions];
            }
            // Net payable to the employee, or receivable from them when recoveries are larger.
            $lines[] = $f->net >= 0 ? ['key' => 'salary_payable', 'credit' => $f->net] : ['key' => 'staff_advance', 'debit' => -$f->net];
            $lines = array_values(array_filter($lines, fn ($l) => (($l['debit'] ?? 0) + ($l['credit'] ?? 0)) > 0));
            $v = $this->books->post('journal', 'fnf_settlement', $f->id, min($f->relieved_on->format('Y-m-d'), today()->format('Y-m-d')), $lines,
                "Full and final settlement: {$f->staff->name}", $f->staff->branch_id, 'FNF-'.$f->staff->employee_code);
            $f->update(['status' => 'approved', 'approved_by' => $this->member->userId(), 'approved_at' => now(), 'voucher_id' => $v->id]);
            $f->staff->update(['status' => 'inactive']);
            Audit::record('payroll.fnf_approved', $f, ['net' => $f->net, 'voucher' => $v->number]);

            return $f;
        });
    }

    public function markPaid(FnfSettlement $f, string $reference): FnfSettlement
    {
        $this->member->authorize('payroll.approve');
        if ($f->status !== 'approved' || $f->net <= 0) {
            throw new HrException('Only an approved settlement with an amount payable can be paid.');
        }
        $v = $this->books->post('payment', 'fnf_payment', $f->id, today()->format('Y-m-d'), [['key' => 'salary_payable', 'debit' => $f->net], ['key' => 'bank_main', 'credit' => $f->net]],
            "Full and final paid: {$f->staff->name}", $f->staff->branch_id, $reference);
        $f->update(['status' => 'paid', 'payment_voucher_id' => $v->id]);
        Audit::record('payroll.fnf_paid', $f, ['reference' => $reference]);

        return $f;
    }
}
