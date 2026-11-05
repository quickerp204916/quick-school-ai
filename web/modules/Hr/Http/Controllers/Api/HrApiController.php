<?php

namespace Modules\Hr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Academic\Support\CurrentMember;
use Modules\Hr\Models\Attendance;
use Modules\Hr\Models\LeaveRequest;
use Modules\Hr\Models\Payslip;
use Modules\Hr\Services\AttendanceService;
use Modules\Hr\Services\LeaveService;

/** HR API for the staff app: self-service, and leave approval for HR. Amounts are paise. */
class HrApiController
{
    public function __construct(private readonly CurrentMember $member, private readonly LeaveService $leave)
    {
    }

    private function staff()
    {
        return $this->member->staff() ?? abort(403, 'Your account is not linked to a staff record.');
    }

    private static function slip(Payslip $p): array
    {
        return ['id' => $p->id, 'period' => $p->run->period, 'label' => $p->run->label(), 'paid_days' => $p->paid_days, 'lop_days' => $p->lop_days,
            'earnings' => $p->earnings, 'deductions' => $p->deductions, 'gross' => $p->gross, 'total_deductions' => $p->total_deductions, 'net' => $p->net,
            'net_display' => \Modules\Accounting\Support\Amount::format($p->net)];
    }

    public function me(): JsonResponse
    {
        $me = $this->staff()->load('profile.department', 'profile.designation');

        return response()->json(['employee' => ['id' => $me->id, 'code' => $me->employee_code, 'name' => $me->name, 'department' => $me->profile?->department?->name,
            'designation' => $me->profile?->designation?->name, 'joined_on' => $me->joined_on?->format('Y-m-d'), 'bank_account' => $me->profile?->maskedAccount()],
            'leave' => collect($this->leave->balances($me))->map(fn ($b) => ['code' => $b['type']->code, 'name' => $b['type']->name, 'balance' => $b['balance'], 'used' => $b['used']])->values()]);
    }

    public function payslips(): JsonResponse
    {
        return response()->json(['data' => Payslip::with('run')->where('staff_member_id', $this->staff()->id)->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))
            ->get()->sortByDesc(fn ($p) => $p->run->period)->map(fn ($p) => self::slip($p))->values()]);
    }

    public function payslip(Payslip $payslip): JsonResponse
    {
        abort_unless($payslip->staff_member_id === $this->staff()->id && $payslip->run->isLocked(), 403);

        return response()->json(['data' => self::slip($payslip)]);
    }

    public function attendance(Request $r, AttendanceService $attendance): JsonResponse
    {
        $me = $this->staff();
        $month = $r->input('month', today()->format('Y-m'));

        return response()->json(['summary' => $attendance->summary($me, $month), 'days' => Attendance::where('staff_member_id', $me->id)->where('date', 'like', $month.'%')
            ->orderBy('date')->get(['date', 'status', 'check_in', 'check_out', 'late_minutes', 'remarks'])]);
    }

    public function applyLeave(Request $r): JsonResponse
    {
        $l = $this->leave->apply($this->staff(), $r->all());

        return response()->json(['data' => ['id' => $l->id, 'days' => $l->days, 'status' => $l->status]], 201);
    }

    public function pendingLeave(): JsonResponse
    {
        $this->member->authorize('hr.manage');

        return response()->json(['data' => LeaveRequest::with(['staff', 'type'])->where('status', 'pending')->orderBy('from_date')->get()
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->staff->name, 'type' => $l->type->name, 'from' => $l->from_date->format('Y-m-d'), 'to' => $l->to_date->format('Y-m-d'), 'days' => $l->days, 'reason' => $l->reason])]);
    }

    public function decideLeave(Request $r, LeaveRequest $leaveRequest): JsonResponse
    {
        $l = $this->leave->decide($leaveRequest, $r->input('decision') === 'approve', $r->input('note'));

        return response()->json(['data' => ['id' => $l->id, 'status' => $l->status]]);
    }
}
