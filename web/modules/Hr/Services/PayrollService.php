<?php

namespace Modules\Hr\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Services\AccountingGateway;
use Modules\Accounting\Support\Amount;
use Modules\Hr\HrException;
use Modules\Hr\Models\EmployeeProfile;
use Modules\Hr\Models\FnfSettlement;
use Modules\Hr\Models\PayrollInput;
use Modules\Hr\Models\PayrollRun;
use Modules\Hr\Models\Payslip;
use Modules\Hr\Models\SalaryComponent;
use Modules\Hr\Models\SalaryStructure;
use Modules\Hr\Models\StatutoryRule;

/**
 * Monthly payroll: process (compute payslips) → approve (a different person; posts the salary journal) → pay
 * (bank file, then the payment voucher). Approved payrolls are locked: payslips, the attendance of that month
 * and the structures and rules they used can no longer change.
 */
final class PayrollService
{
    public function __construct(private readonly CurrentMember $member, private readonly PayrollEngine $engine, private readonly AccountingGateway $books)
    {
    }

    /** Staff paid in a month: active or relieved after the month began, with a structure, and not settled through full and final. */
    public function eligible(string $period)
    {
        $start = $period.'-01';
        $end = CarbonImmutable::parse($start)->endOfMonth()->format('Y-m-d');
        $relieved = EmployeeProfile::whereNotNull('relieved_on')->where('relieved_on', '<', $start)->pluck('staff_member_id');
        $settled = FnfSettlement::pluck('staff_member_id');

        return StaffMember::with('branch')->whereIn('status', ['active', 'inactive'])
            ->where(fn ($q) => $q->whereNull('joined_on')->orWhere('joined_on', '<=', $end))
            ->whereNotIn('id', $relieved)->whereNotIn('id', $settled)
            ->whereIn('id', SalaryStructure::where('effective_from', '<=', $end)->select('staff_member_id'))->orderBy('name')->get();
    }

    public function process(string $period): PayrollRun
    {
        $this->member->authorize('payroll.process');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new HrException('Choose the month.', 'period');
        }
        if ($period > today()->format('Y-m')) {
            throw new HrException('Payroll can be processed for this month or earlier.', 'period');
        }
        $previous = PayrollRun::where('period', '<', $period)->orderByDesc('period')->first();
        if ($previous && ! $previous->isLocked()) {
            throw new HrException("Approve the {$previous->label()} payroll first, so income tax is projected on approved figures.", 'period');
        }

        return DB::transaction(function () use ($period) {
            $run = PayrollRun::lockForUpdate()->firstOrNew(['period' => $period]);
            if ($run->isLocked()) {
                throw new HrException("Payroll for {$run->label()} is already approved.");
            }
            $run->save();
            $run->payslips()->delete();
            $staff = $this->eligible($period);
            if ($staff->isEmpty()) {
                throw new HrException('Nobody has a salary structure for this month yet.');
            }
            foreach ($staff as $s) {
                $run->payslips()->create(['staff_member_id' => $s->id] + $this->engine->compute($s, $period));
            }
            $slips = $run->payslips()->get();
            $run->fill(['status' => 'processed', 'employees' => $slips->count(), 'gross' => (int) $slips->sum('gross'), 'deductions' => (int) $slips->sum('total_deductions'),
                'net' => (int) $slips->sum('net'), 'employer_cost' => (int) $slips->sum(fn ($p) => ($p->employer['PF'] ?? 0) + ($p->employer['ESI'] ?? 0)),
                'processed_by' => $this->member->userId(), 'processed_at' => now(), 'approved_by' => null, 'approved_at' => null])->save();
            Audit::record('payroll.processed', $run, ['employees' => $run->employees, 'net' => $run->net]);

            return $run;
        });
    }

    /**
     * Approval by someone other than the person who processed it. Posts the salary journal:
     * Dr salaries (and overtime), employer PF and ESI; Cr PF, ESI, PT and TDS payable, advance recovery, salaries payable.
     */
    public function approve(PayrollRun $run): PayrollRun
    {
        $this->member->authorize('payroll.approve');
        if ($run->status !== 'processed') {
            throw new HrException('Only a processed payroll can be approved.');
        }
        if ($run->processed_by === $this->member->userId()) {
            throw new HrException('You processed this payroll, so another approver has to approve it.');
        }

        return DB::transaction(function () use ($run) {
            $run = PayrollRun::lockForUpdate()->find($run->id);
            $c = SalaryComponent::pluck('ledger_id', 'code');
            $dr = $cr = [];
            foreach ($run->payslips as $p) {
                foreach ($p->earnings as $code => $a) {
                    $dr[$c[$code]] = ($dr[$c[$code]] ?? 0) + $a;
                }
                foreach ($p->deductions as $code => $a) {
                    $cr[$c[$code]] = ($cr[$c[$code]] ?? 0) + $a;
                }
                foreach (['PF' => ['pf_employer', 'pf_payable'], 'ESI' => ['esi_employer', 'esi_payable']] as $k => [$exp, $liab]) {
                    if ($a = $p->employer[$k] ?? 0) {
                        $dr[Ledger::system($exp)->id] = ($dr[Ledger::system($exp)->id] ?? 0) + $a;
                        $cr[Ledger::system($liab)->id] = ($cr[Ledger::system($liab)->id] ?? 0) + $a;
                    }
                }
            }
            $sal = Ledger::system('salary_payable')->id;
            $cr[$sal] = ($cr[$sal] ?? 0) + $run->net;
            $lines = [...array_map(fn ($l, $a) => ['ledger_id' => $l, 'debit' => $a], array_keys($dr), $dr), ...array_map(fn ($l, $a) => ['ledger_id' => $l, 'credit' => $a], array_keys($cr), $cr)];
            $end = CarbonImmutable::parse($run->period.'-01')->endOfMonth()->format('Y-m-d');
            $v = $this->books->post('journal', 'payroll_run', $run->id, min($end, today()->format('Y-m-d')), array_values(array_filter($lines, fn ($l) => ($l['debit'] ?? $l['credit']) > 0)),
                "Salaries for {$run->label()} ({$run->employees} employees)", null, 'PAYROLL-'.$run->period);
            $run->update(['status' => 'approved', 'approved_by' => $this->member->userId(), 'approved_at' => now(), 'voucher_id' => $v->id]);
            Audit::record('payroll.approved', $run, ['net' => $run->net, 'voucher' => $v->number]);

            return $run;
        });
    }

    /** Returns a processed payroll to draft (for corrections). Approved payroll cannot be reopened. */
    public function reopen(PayrollRun $run): PayrollRun
    {
        $this->member->authorize('payroll.process');
        if ($run->isLocked()) {
            throw new HrException('Approved payroll is locked. Correct it next month with an arrears or recovery input.');
        }
        $run->payslips()->delete();
        $run->update(['status' => 'draft', 'employees' => 0, 'gross' => 0, 'deductions' => 0, 'net' => 0, 'employer_cost' => 0]);
        Audit::record('payroll.reopened', $run);

        return $run;
    }

    /** NEFT bulk upload file for the bank. Only for approved payroll; every download is audited. */
    public function bankFile(PayrollRun $run): array
    {
        $this->member->authorize('payroll.approve');
        if (! $run->isLocked()) {
            throw new HrException('Approve the payroll before creating the bank file.');
        }
        $rows = [];
        $missing = [];
        foreach ($run->payslips()->with('staff')->get() as $p) {
            $prof = EmployeeProfile::where('staff_member_id', $p->staff_member_id)->first();
            if (! $prof?->bank_account || ! $prof->bank_ifsc) {
                $missing[] = $p->staff->name;
                continue;
            }
            $rows[] = ['N', $prof->account_holder ?: $p->staff->name, $prof->bank_account, $prof->bank_ifsc, number_format($p->net / 100, 2, '.', ''),
                'SALARY '.strtoupper(date('M Y', strtotime($run->period.'-01'))), $p->staff->employee_code, $p->staff->email];
        }
        $run->update(['bank_file_at' => now()]);
        Audit::record('payroll.bank_file', $run, ['rows' => count($rows), 'missing' => $missing]);

        return ['header' => ['Payment type', 'Beneficiary name', 'Account number', 'IFSC', 'Amount', 'Narration', 'Employee code', 'Email'], 'rows' => $rows, 'missing' => $missing];
    }

    /** The bank has paid the salaries: Dr Salaries payable, Cr Bank. */
    public function markPaid(PayrollRun $run, string $reference, ?string $date = null): PayrollRun
    {
        $this->member->authorize('payroll.approve');
        if ($run->status !== 'approved') {
            throw new HrException('Only approved payroll can be marked as paid.');
        }
        if (mb_strlen(trim($reference)) < 3) {
            throw new HrException('Enter the bank batch or UTR reference.', 'reference');
        }
        $v = $this->books->post('payment', 'payroll_payment', $run->id, $date ?? today()->format('Y-m-d'),
            [['key' => 'salary_payable', 'debit' => $run->net], ['key' => 'bank_main', 'credit' => $run->net]], "Salaries paid for {$run->label()}", null, $reference);
        $run->update(['status' => 'paid', 'payment_voucher_id' => $v->id, 'payment_reference' => $reference]);
        Audit::record('payroll.paid', $run, ['reference' => $reference]);

        return $run;
    }

    /** A one-off for a month: bonus or arrears (earning), advance recovery or other deduction. */
    public function addInput(StaffMember $staff, array $d): PayrollInput
    {
        $this->member->authorize('payroll.process');
        $period = (string) ($d['period'] ?? '');
        if (PayrollRun::where('period', $period)->whereIn('status', ['approved', 'paid'])->exists()) {
            throw new HrException('Payroll for this month is approved. Add the amount to next month.', 'period');
        }
        if (! in_array($d['component'] ?? '', ['BONUS', 'ADVANCE', 'OTHER'], true)) {
            throw new HrException('Choose bonus or arrears, advance recovery or other deduction.', 'component');
        }
        $amount = Amount::parse($d['amount'] ?? 0);
        if ($amount <= 0) {
            throw new HrException('Enter the amount.', 'amount');
        }
        $in = PayrollInput::create(['staff_member_id' => $staff->id, 'period' => $period, 'component' => $d['component'], 'amount' => $amount,
            'note' => $d['note'] ?? null, 'created_by' => $this->member->userId()]);
        Audit::record('payroll.input_added', $in, ['component' => $d['component'], 'amount' => $amount]);

        return $in;
    }

    /** A new version of a statutory rule. It may not start inside a month whose payroll is already approved. */
    public function addRule(string $code, string $from, array $config, ?string $note = null): StatutoryRule
    {
        $this->member->authorize('payroll.configure');
        if (! array_key_exists($code, config('hr.statutory'))) {
            throw new HrException('Unknown statutory rule.', 'code');
        }
        $locked = PayrollRun::whereIn('status', ['approved', 'paid'])->max('period');
        if ($locked && substr($from, 0, 7) <= $locked) {
            throw new HrException("Payroll up to {$locked} is approved with the current rules. The new version must start after that.", 'effective_from');
        }
        $base = StatutoryRule::inForce($code, $from)?->config ?? config("hr.statutory.{$code}.1");
        $r = StatutoryRule::create(['code' => $code, 'effective_from' => $from, 'config' => array_replace_recursive($base, $config), 'note' => $note, 'created_by' => $this->member->userId()]);
        Audit::record('payroll.rule_added', null, ['code' => $code, 'from' => $from]);

        return $r;
    }
}
