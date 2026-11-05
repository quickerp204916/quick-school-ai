<?php

namespace Modules\Hr\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Support\Csv;
use Modules\Hr\Models\FnfSettlement;
use Modules\Hr\Models\PayrollRun;
use Modules\Hr\Models\Payslip;
use Modules\Hr\Models\SalaryComponent;
use Modules\Hr\Services\FnfService;
use Modules\Hr\Services\PayrollReports;
use Modules\Hr\Services\PayrollService;

class PayrollController extends \Modules\Academic\Http\Controllers\Controller
{
    public function __construct(private readonly PayrollService $payroll, private readonly FnfService $fnf)
    {
    }

    public function index(): View
    {
        return view('hr::payroll.index', ['runs' => PayrollRun::orderByDesc('period')->get(), 'next' => today()->format('Y-m')]);
    }

    public function process(Request $r): RedirectResponse
    {
        $run = $this->payroll->process((string) $r->input('period'));

        return $this->done("Payroll for {$run->label()} processed for {$run->employees} employees. Review it, then ask an approver to approve.", route('app.hr.payroll.show', $run));
    }

    public function show(PayrollRun $payrollRun, PayrollReports $reports): View
    {
        return view('hr::payroll.show', ['run' => $payrollRun, 'slips' => $reports->register($payrollRun), 'components' => SalaryComponent::orderBy('sort_order')->get()->keyBy('code')]);
    }

    public function approve(PayrollRun $payrollRun): RedirectResponse
    {
        $this->payroll->approve($payrollRun);

        return $this->done('Payroll approved and the salary journal posted to the books.');
    }

    public function reopen(PayrollRun $payrollRun): RedirectResponse
    {
        $this->payroll->reopen($payrollRun);

        return $this->done('Payroll reopened. Process it again after making corrections.');
    }

    public function bankFile(PayrollRun $payrollRun)
    {
        $f = $this->payroll->bankFile($payrollRun);

        return Csv::download("salary-{$payrollRun->period}-neft.csv", $f['header'], $f['rows']);
    }

    public function paid(Request $r, PayrollRun $payrollRun): RedirectResponse
    {
        $this->payroll->markPaid($payrollRun, (string) $r->input('reference'), $r->input('date'));

        return $this->done('Salary payment posted.');
    }

    public function report(PayrollRun $payrollRun, string $report, PayrollReports $reports, Request $r)
    {
        $rows = $reports->{$report}($payrollRun);
        if ($r->input('format') === 'csv') {
            return Csv::download("{$report}-{$payrollRun->period}.csv", array_keys($rows->first() ?? ['empty' => '']), $rows->map(fn ($x) => array_values($x))->all());
        }

        return view('hr::payroll.report', ['run' => $payrollRun, 'report' => $report, 'rows' => $rows]);
    }

    public function payslip(Payslip $payslip): View
    {
        return view('hr::payroll.payslip', ['p' => $payslip->load(['staff.profile.department', 'staff.profile.designation', 'run']), 'components' => SalaryComponent::get()->keyBy('code')]);
    }

    public function fnfIndex(): View
    {
        return view('hr::payroll.fnf-index', ['list' => FnfSettlement::with('staff')->latest()->get(),
            'relieved' => StaffMember::whereHas('profile', fn ($q) => $q->whereNotNull('relieved_on'))->whereNotIn('id', FnfSettlement::select('staff_member_id'))->get()]);
    }

    public function fnfPrepare(Request $r, StaffMember $staff): RedirectResponse
    {
        $f = $this->fnf->prepare($staff, $r->all());

        return $this->done('Settlement worked out. An approver has to approve it.', route('app.hr.fnf.show', $f));
    }

    public function fnfShow(FnfSettlement $fnf): View
    {
        return view('hr::payroll.fnf', ['f' => $fnf->load('staff')]);
    }

    public function fnfApprove(FnfSettlement $fnf): RedirectResponse
    {
        $this->fnf->approve($fnf);

        return $this->done('Settlement approved and posted to the books.');
    }

    public function fnfPaid(Request $r, FnfSettlement $fnf): RedirectResponse
    {
        $this->fnf->markPaid($fnf, (string) $r->input('reference'));

        return $this->done('Settlement payment posted.');
    }
}
