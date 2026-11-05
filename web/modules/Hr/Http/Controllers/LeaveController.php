<?php

namespace Modules\Hr\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\StaffMember;
use Modules\Hr\Models\LeaveRequest;
use Modules\Hr\Models\LeaveType;
use Modules\Hr\Services\LeaveService;

class LeaveController extends \Modules\Academic\Http\Controllers\Controller
{
    public function __construct(private readonly LeaveService $leave)
    {
    }

    public function index(Request $r): View
    {
        $status = $r->input('status', 'pending');

        return view('hr::leave.index', ['status' => $status, 'types' => LeaveType::where('is_active', true)->get(), 'staff' => StaffMember::where('status', 'active')->orderBy('name')->get(),
            'list' => LeaveRequest::with(['staff', 'type'])->when($status !== 'all', fn ($q) => $q->where('status', $status))->orderByDesc('from_date')->paginate(50)->withQueryString()]);
    }

    public function store(Request $r): RedirectResponse
    {
        $this->leave->apply(StaffMember::findOrFail($r->input('staff_member_id')), $r->all());

        return $this->done('Leave application recorded. It needs approval by someone other than the employee.');
    }

    public function decide(Request $r, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->leave->decide($leaveRequest, $r->input('decision') === 'approve', $r->input('note'));

        return $this->done($r->input('decision') === 'approve' ? 'Leave approved and marked in attendance.' : 'Leave rejected.');
    }

    public function cancel(LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->leave->cancel($leaveRequest);

        return $this->done('Leave cancelled.');
    }
}
