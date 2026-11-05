<?php

namespace Modules\Hr\Services;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Fees\Support as Fy;
use Modules\Hr\HrException;
use Modules\Hr\Models\Attendance;
use Modules\Hr\Models\LeaveAllocation;
use Modules\Hr\Models\LeaveRequest;
use Modules\Hr\Models\LeaveType;
use Modules\Hr\Models\PayrollRun;

/** Leave balances, applications (by the employee or HR) and approval by someone else, which marks attendance. */
final class LeaveService
{
    public function __construct(private readonly CurrentMember $member, private readonly AttendanceService $attendance)
    {
    }

    /** Yearly allotment for a staff member, created on first use; unused days carry forward up to each type's limit. */
    public function allocation(StaffMember $staff, LeaveType $type, string $fy): LeaveAllocation
    {
        if ($a = LeaveAllocation::where('staff_member_id', $staff->id)->where('leave_type_id', $type->id)->where('year', $fy)->first()) {
            return $a;
        }
        $prevFy = ((int) substr($fy, 0, 4) - 1).'-'.substr($fy, 2, 2);
        $carry = 0.0;
        if ($type->carry_forward_max > 0 && LeaveAllocation::where('staff_member_id', $staff->id)->where('leave_type_id', $type->id)->where('year', $prevFy)->exists()) {
            $carry = min($type->carry_forward_max, $this->balance($staff, $type, $prevFy));
        }

        return LeaveAllocation::create(['staff_member_id' => $staff->id, 'leave_type_id' => $type->id, 'year' => $fy, 'days' => $type->days_per_year + $carry]);
    }

    public function used(StaffMember $staff, LeaveType $type, string $fy, ?string $exceptId = null): float
    {
        [$from, $to] = [substr($fy, 0, 4).'-04-01', (substr($fy, 0, 4) + 1).'-03-31'];

        return (float) LeaveRequest::where('staff_member_id', $staff->id)->where('leave_type_id', $type->id)->whereIn('status', ['pending', 'approved'])
            ->whereBetween('from_date', [$from, $to])->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->sum('days');
    }

    public function balance(StaffMember $staff, LeaveType $type, string $fy): float
    {
        return $this->allocation($staff, $type, $fy)->days - $this->used($staff, $type, $fy);
    }

    /** Balances for every leave type this year. */
    public function balances(StaffMember $staff, ?string $fy = null): array
    {
        $fy ??= Fy::fy(today()->format('Y-m-d'));

        return LeaveType::where('is_active', true)->get()->map(fn ($t) => ['type' => $t, 'allotted' => $t->is_paid ? $this->allocation($staff, $t, $fy)->days : null,
            'used' => $this->used($staff, $t, $fy), 'balance' => $t->is_paid ? $this->balance($staff, $t, $fy) : null])->all();
    }

    public function apply(StaffMember $staff, array $d): LeaveRequest
    {
        $self = $this->member->staff()?->id === $staff->id;
        if (! $self) {
            $this->member->authorize('hr.manage');
        }
        $type = LeaveType::where('is_active', true)->find($d['leave_type_id'] ?? null) ?? throw new HrException('Choose the type of leave.', 'leave_type_id');
        $from = $d['from_date'] ?? null;
        $to = ($d['to_date'] ?? null) ?: $from;
        if (! $from || ! strtotime($from) || ! strtotime($to) || $to < $from) {
            throw new HrException('Choose the dates of the leave.', 'from_date');
        }
        if (Fy::fy($from) !== Fy::fy($to)) {
            throw new HrException('Apply separately for leave on either side of 1 April.', 'to_date');
        }
        $half = (bool) ($d['half_day'] ?? false);
        if ($half && $from !== $to) {
            throw new HrException('A half day is a single date.', 'half_day');
        }
        if (mb_strlen(trim((string) ($d['reason'] ?? ''))) < 3) {
            throw new HrException('Give a short reason.', 'reason');
        }
        $days = $this->attendance->workingDays($staff->id, $from, $to, $staff->branch_id);
        if (! $days) {
            throw new HrException('There are no working days in these dates (weekly offs and holidays are not counted).', 'from_date');
        }
        $count = $half ? 0.5 : (float) count($days);
        if (LeaveRequest::where('staff_member_id', $staff->id)->whereIn('status', ['pending', 'approved'])
            ->where('from_date', '<=', $to)->where('to_date', '>=', $from)->exists()) {
            throw new HrException('There is already a leave application covering some of these dates.', 'from_date');
        }
        if ($type->is_paid && $count > $this->balance($staff, $type, Fy::fy($from))) {
            throw new HrException("Only {$this->balance($staff, $type, Fy::fy($from))} day(s) of {$type->name} are left. Choose leave without pay for the rest.", 'leave_type_id');
        }
        $r = LeaveRequest::create(['staff_member_id' => $staff->id, 'leave_type_id' => $type->id, 'from_date' => $from, 'to_date' => $to, 'half_day' => $half,
            'days' => $count, 'reason' => trim($d['reason']), 'status' => 'pending', 'requested_by' => $this->member->userId()]);
        Audit::record('hr.leave_applied', $r, ['days' => $count, 'type' => $type->code]);

        return $r;
    }

    /** Approval marks every working day of the leave in the attendance register. Nobody approves their own leave. */
    public function decide(LeaveRequest $r, bool $approve, ?string $note = null): LeaveRequest
    {
        $this->member->authorize('hr.manage');
        if ($r->status !== 'pending') {
            throw new HrException('This leave application has already been decided.');
        }
        if ($this->member->staff()?->id === $r->staff_member_id || $r->requested_by === $this->member->userId() && $this->member->staff()?->id === $r->staff_member_id) {
            throw new HrException('You cannot decide your own leave.');
        }
        DB::transaction(function () use ($r, $approve, $note) {
            if ($approve) {
                foreach ($this->attendance->workingDays($r->staff_member_id, $r->from_date->format('Y-m-d'), $r->to_date->format('Y-m-d'), $r->staff->branch_id) as $day) {
                    if (PayrollRun::where('period', substr($day, 0, 7))->whereIn('status', ['approved', 'paid'])->exists()) {
                        throw new HrException('Payroll for '.date('F Y', strtotime($day)).' is already approved. Record this leave as an adjustment instead.');
                    }
                    Attendance::updateOrCreate(['staff_member_id' => $r->staff_member_id, 'date' => $day], ['status' => $r->half_day ? 'half_day' : 'leave',
                        'paid_leave' => $r->type->is_paid, 'source' => 'leave', 'leave_request_id' => $r->id, 'remarks' => $r->type->name]);
                }
                // A paid half day is not a loss of pay: record it as present with a note.
                if ($r->half_day && $r->type->is_paid) {
                    Attendance::where('leave_request_id', $r->id)->update(['status' => 'present', 'remarks' => 'Half day '.$r->type->name]);
                }
            }
            $r->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $this->member->userId(), 'decided_at' => now(), 'decision_note' => $note]);
            Audit::record($approve ? 'hr.leave_approved' : 'hr.leave_rejected', $r);
        });

        return $r;
    }

    public function cancel(LeaveRequest $r): LeaveRequest
    {
        $self = $this->member->staff()?->id === $r->staff_member_id;
        if (! $self) {
            $this->member->authorize('hr.manage');
        }
        if ($r->status === 'approved') {
            if (! $this->member->can('hr.manage')) {
                throw new HrException('Approved leave can be cancelled by HR only.');
            }
            if (PayrollRun::whereBetween('period', [$r->from_date->format('Y-m'), $r->to_date->format('Y-m')])->whereIn('status', ['approved', 'paid'])->exists()) {
                throw new HrException('Payroll covering this leave is approved, so it can no longer be cancelled.');
            }
        } elseif ($r->status !== 'pending') {
            throw new HrException('Only pending or approved leave can be cancelled.');
        }
        DB::transaction(function () use ($r) {
            Attendance::where('leave_request_id', $r->id)->delete();
            $r->update(['status' => 'cancelled']);
            Audit::record('hr.leave_cancelled', $r);
        });

        return $r;
    }
}
