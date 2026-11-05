<?php

namespace Modules\Hr\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Hr\Models\Attendance;
use Modules\Hr\Models\EmployeeDocument;
use Modules\Hr\Models\LeaveRequest;
use Modules\Hr\Models\LeaveType;
use Modules\Hr\Models\Payslip;
use Modules\Hr\Models\SalaryComponent;
use Modules\Hr\Services\EmployeeService;
use Modules\Hr\Services\LeaveService;

/** Employee self-service: my profile, leave, attendance, payslips and documents. Only ever one's own records. */
class SelfServiceController extends \Modules\Academic\Http\Controllers\Controller
{
    public function __construct(private readonly LeaveService $leave)
    {
    }

    private function me()
    {
        return $this->member()->staff() ?? abort(403, 'Your account is not linked to a staff record. Ask HR to link it.');
    }

    public function index(): View
    {
        $me = $this->me()->load('profile.department', 'profile.designation');

        return view('hr::me.index', ['me' => $me, 'balances' => $this->leave->balances($me), 'types' => LeaveType::where('is_active', true)->get(),
            'leave' => LeaveRequest::with('type')->where('staff_member_id', $me->id)->orderByDesc('from_date')->limit(10)->get(),
            'payslips' => Payslip::with('run')->where('staff_member_id', $me->id)->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))->latest()->get(),
            'attendance' => Attendance::where('staff_member_id', $me->id)->where('date', '>=', today()->subDays(30)->format('Y-m-d'))->orderByDesc('date')->get(),
            'documents' => EmployeeDocument::where('staff_member_id', $me->id)->get()]);
    }

    public function applyLeave(Request $r): RedirectResponse
    {
        $this->leave->apply($this->me(), $r->all());

        return $this->done('Leave applied. You will see the decision here.');
    }

    public function cancelLeave(LeaveRequest $leaveRequest): RedirectResponse
    {
        abort_unless($leaveRequest->staff_member_id === $this->me()->id, 403);
        $this->leave->cancel($leaveRequest);

        return $this->done('Leave cancelled.');
    }

    public function payslip(Payslip $payslip): View
    {
        abort_unless($payslip->staff_member_id === $this->me()->id && $payslip->run->isLocked(), 403);

        return view('hr::payroll.payslip', ['p' => $payslip->load(['staff.profile.department', 'staff.profile.designation', 'run']), 'components' => SalaryComponent::get()->keyBy('code')]);
    }

    public function document(EmployeeDocument $employeeDocument, EmployeeService $employees)
    {
        return $employees->download($employeeDocument);
    }
}
