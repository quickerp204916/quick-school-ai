<?php

namespace Modules\Hr\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Models\Holiday;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Hr\HrException;
use Modules\Hr\Models\Attendance;
use Modules\Hr\Models\EmployeeProfile;
use Modules\Hr\Models\PayrollRun;
use Modules\Hr\Models\Shift;

/**
 * Staff attendance. Working days nobody marked count as present, so the register only needs exceptions;
 * absences, half days and leave without pay reduce the days paid.
 */
final class AttendanceService
{
    public function __construct(private readonly CurrentMember $member)
    {
    }

    public function shiftOf(string $staffId): Shift
    {
        return EmployeeProfile::where('staff_member_id', $staffId)->first()?->shift ?? Shift::orderBy('created_at')->firstOrFail();
    }

    public function isWorkingDay(string $staffId, string $date, ?string $branchId = null): bool
    {
        return ! in_array((int) date('w', strtotime($date)), $this->shiftOf($staffId)->weekly_off, true) && ! Holiday::covering($date, $branchId);
    }

    private function lockedFor(string $date): void
    {
        if (PayrollRun::where('period', substr($date, 0, 7))->whereIn('status', ['approved', 'paid'])->exists()) {
            throw new HrException('Payroll for '.date('F Y', strtotime($date)).' is approved, so its attendance can no longer change.', 'date');
        }
    }

    /** Saves the day's register. $rows: staff id => [status, check_in, check_out, overtime_minutes, remarks]. */
    public function mark(string $date, array $rows): int
    {
        $this->member->authorize('hr.manage');
        if ($date > today()->format('Y-m-d')) {
            throw new HrException('Attendance cannot be marked for a future date.', 'date');
        }
        $this->lockedFor($date);
        $n = 0;
        DB::transaction(function () use ($date, $rows, &$n) {
            foreach ($rows as $staffId => $r) {
                $status = $r['status'] ?? '';
                if ($status === '') {
                    continue;
                }
                if (! array_key_exists($status, Attendance::STATUSES)) {
                    throw new HrException('Unknown attendance status.');
                }
                $existing = Attendance::where('staff_member_id', $staffId)->where('date', $date)->first();
                if ($existing?->source === 'leave' && $status !== 'leave') {
                    throw new HrException('One of the staff is on approved leave that day. Cancel the leave to change it.');
                }
                $in = $r['check_in'] ?? null ?: null;
                $late = 0;
                if ($in && $status === 'present') {
                    $shift = $this->shiftOf($staffId);
                    $late = max(0, (int) ((strtotime("{$date} {$in}") - strtotime("{$date} {$shift->starts_at}")) / 60) - $shift->grace_minutes);
                }
                Attendance::updateOrCreate(['staff_member_id' => $staffId, 'date' => $date], ['status' => $status, 'check_in' => $in, 'check_out' => $r['check_out'] ?? null ?: null,
                    'late_minutes' => $late, 'overtime_minutes' => max(0, (int) ($r['overtime_minutes'] ?? 0)), 'paid_leave' => $status !== 'leave' || ($r['paid_leave'] ?? true),
                    'source' => $existing?->source === 'leave' ? 'leave' : ($r['source'] ?? 'manual'), 'remarks' => $r['remarks'] ?? null]);
                $n++;
            }
        });
        Audit::record('hr.attendance_marked', null, ['date' => $date, 'rows' => $n]);

        return $n;
    }

    /** Biometric or app export: employee_code,date,check_in,check_out. Present if both punches, half day if under half the shift. */
    public function import(string $csv): array
    {
        $this->member->authorize('hr.manage');
        $codes = StaffMember::pluck('id', 'employee_code');
        $done = $skipped = 0;
        foreach (preg_split('/\r?\n/', trim($csv)) as $i => $line) {
            $c = array_map('trim', str_getcsv($line));
            if ($i === 0 && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $c[1] ?? '')) {
                continue;   // header row
            }
            [$code, $date, $in, $out] = array_pad($c, 4, null);
            if (! isset($codes[$code]) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                $skipped++;
                continue;
            }
            $shift = $this->shiftOf($codes[$code]);
            $worked = $in && $out ? (int) ((strtotime("{$date} {$out}") - strtotime("{$date} {$in}")) / 60) : 0;
            $status = ! $in ? 'absent' : ($worked && $worked < $shift->minutes() / 2 ? 'half_day' : 'present');
            $this->mark($date, [$codes[$code] => ['status' => $status, 'check_in' => $in, 'check_out' => $out, 'source' => 'import']]);
            $done++;
        }

        return ['imported' => $done, 'skipped' => $skipped];
    }

    /** What payroll needs for a month: days in month, days in service, loss-of-pay days and approved overtime. */
    public function summary(StaffMember $staff, string $period): array
    {
        $start = CarbonImmutable::parse($period.'-01');
        $end = $start->endOfMonth();
        $profile = EmployeeProfile::where('staff_member_id', $staff->id)->first();
        $from = max($start->format('Y-m-d'), $staff->joined_on?->format('Y-m-d') ?? '0000-00-00');
        $to = min($end->format('Y-m-d'), $profile?->relieved_on?->format('Y-m-d') ?? '9999-12-31');
        $rows = Attendance::where('staff_member_id', $staff->id)->whereBetween('date', [$from, $to])->get();

        return ['days_in_month' => $start->daysInMonth, 'from' => $from, 'to' => $to,
            'payable_days' => $from > $to ? 0.0 : (float) (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1),
            'lop_days' => (float) $rows->sum(fn ($r) => $r->lop()), 'overtime_minutes' => (int) $rows->sum('overtime_minutes'),
            'absent' => $rows->where('status', 'absent')->count(), 'half_days' => $rows->where('status', 'half_day')->count(),
            'leave' => $rows->where('status', 'leave')->count(), 'late' => $rows->where('late_minutes', '>', 0)->count()];
    }

    /** Working days between two dates for a staff member (weekly offs and holidays excluded). */
    public function workingDays(string $staffId, string $from, string $to, ?string $branchId = null): array
    {
        return collect(CarbonPeriod::create($from, $to))->map->format('Y-m-d')->filter(fn ($d) => $this->isWorkingDay($staffId, $d, $branchId))->values()->all();
    }
}
