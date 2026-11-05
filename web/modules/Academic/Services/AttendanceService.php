<?php

namespace Modules\Academic\Services;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Academic\Events\StudentsMarkedAbsent;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Models\AttendanceEntry;
use Modules\Academic\Models\AttendanceRegister;
use Modules\Academic\Models\BellPeriod;
use Modules\Academic\Models\Holiday;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\SectionSubject;
use Modules\Academic\Models\Student;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Academic\Support\Institution;
use Modules\Academic\Support\Rules;

/** Daily or period-wise attendance registers, and attendance reports. */
final class AttendanceService
{
    public function __construct(private readonly CurrentMember $member)
    {
    }

    /** The register for a section, date and period: saved statuses, or everyone present by default. */
    public function sheet(Section $section, string $date, ?string $periodId = null): array
    {
        $register = AttendanceRegister::where('section_id', $section->id)->whereDate('date', $date)
            ->where(fn ($q) => $periodId ? $q->where('period_id', $periodId) : $q->whereNull('period_id'))->first();
        $saved = $register ? AttendanceEntry::where('register_id', $register->id)->get()->keyBy('student_id') : collect();
        $rows = StudentService::rosterOn($section, $date)->map(fn ($e) => [
            'student' => $e->student, 'roll_no' => $e->roll_no,
            'status' => $saved[$e->student_id]->status ?? 'present', 'remark' => $saved[$e->student_id]->remark ?? null,
        ]);

        return ['register' => $register, 'rows' => $rows, 'blocked' => $this->blockedReason($section, $date)];
    }

    /** Why attendance cannot be taken on this date, or null. */
    public function blockedReason(Section $section, string $date): ?string
    {
        $section->loadMissing('session');
        $d = Carbon::parse($date);
        if ($d->isAfter(today())) {
            return 'Attendance cannot be marked for a future date.';
        }
        if (! $section->session->contains($date)) {
            return "The date is outside session {$section->session->name}.";
        }
        if (! Institution::profile()->isWorkingDay($d)) {
            return $d->format('l').' is not a working day.';
        }
        if ($h = Holiday::covering($date, $section->branch_id)) {
            return "{$d->format('j M Y')} is a holiday: {$h->name}.";
        }

        return null;
    }

    /**
     * @param array{date: string, period_id?: ?string, subject_id?: ?string, entries: array<string, array{status: string, remark?: ?string}|string>} $data
     */
    public function mark(Section $section, array $data): AttendanceRegister
    {
        $entries = collect($data['entries'] ?? [])->map(fn ($v) => is_array($v) ? $v : ['status' => $v])->all();
        $d = Rules::check(['entries' => $entries] + $data, [
            'date' => ['required', 'date_format:Y-m-d'],
            'period_id' => ['nullable', Rules::exists('bell_periods', ['branch_id' => $section->branch_id, 'is_break' => false])],
            'subject_id' => ['nullable', Rules::exists('subjects')],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.status' => ['required', Rule::in(array_keys(config('academic.attendance.statuses')))],
            'entries.*.remark' => ['nullable', 'string', 'max:150'],
        ], ['entries.required' => 'There are no students to mark.']);

        $profile = Institution::profile();
        $periodId = $d['period_id'] ?? null;
        if ($profile->attendance_mode === 'period' && ! $periodId) {
            throw new AcademicRuleException('This institution takes attendance period by period. Choose the period.', 'period_id');
        }
        if ($profile->attendance_mode === 'daily' && $periodId) {
            throw new AcademicRuleException('This institution takes attendance once a day. Leave the period empty.', 'period_id');
        }
        $subjectId = $d['subject_id'] ?? null;
        if ($subjectId && ! SectionSubject::where('section_id', $section->id)->where('subject_id', $subjectId)->exists()) {
            throw new AcademicRuleException('This subject is not taught in this section.', 'subject_id');
        }
        $this->member->mustTeach($section, $periodId ? $subjectId : null);
        if ($reason = $this->blockedReason($section, $d['date'])) {
            throw new AcademicRuleException($reason, 'date');
        }

        $existing = AttendanceRegister::where('section_id', $section->id)->whereDate('date', $d['date'])
            ->where(fn ($q) => $periodId ? $q->where('period_id', $periodId) : $q->whereNull('period_id'))->first();
        $age = Carbon::parse($d['date'])->diffInDays(today());
        if ($age > $profile->attendance_edit_days && ! $this->member->can('setup.manage')) {
            throw new AcademicRuleException("Attendance older than {$profile->attendance_edit_days} days can only be marked or changed by an administrator.", 'date');
        }

        $roster = StudentService::rosterOn($section, $d['date'])->pluck('student_id')->all();
        $given = array_keys($d['entries']);
        if ($extra = array_diff($given, $roster)) {
            throw new AcademicRuleException(count($extra).' student(s) in the list are not in this '.strtolower(Institution::term('section')).' on this date.', 'entries');
        }
        if ($missing = array_diff($roster, $given)) {
            $names = Student::whereIn('id', $missing)->get()->map->fullName()->take(3)->implode(', ');
            throw new AcademicRuleException('Mark every student. Missing: '.$names.(count($missing) > 3 ? ' and '.(count($missing) - 3).' more' : '').'.', 'entries');
        }

        $register = DB::transaction(function () use ($section, $d, $periodId, $subjectId, $existing) {
            $register = $existing ?? new AttendanceRegister(['section_id' => $section->id, 'date' => $d['date'], 'period_id' => $periodId]);
            $register->fill(['subject_id' => $subjectId, 'marked_by' => $this->member->userId(), 'marked_at' => now()])->save();
            AttendanceEntry::where('register_id', $register->id)->delete();
            foreach ($d['entries'] as $studentId => $e) {
                AttendanceEntry::create(['register_id' => $register->id, 'student_id' => $studentId, 'status' => $e['status'], 'remark' => $e['remark'] ?? null]);
            }
            if ($existing) {
                Audit::record('attendance.updated', $register, ['date' => $d['date']]);
            }

            return $register;
        });

        $absent = collect($d['entries'])->filter(fn ($e) => $e['status'] === 'absent')->keys()->all();
        if ($absent) {
            StudentsMarkedAbsent::dispatch(TenantContext::id(), $register, $absent);
        }

        return $register;
    }

    /* ---------- Reports ---------- */

    /** Counts and percentage for one student between two dates. */
    public function studentSummary(string $studentId, string $from, string $to): array
    {
        $rows = AttendanceEntry::query()->join('attendance_registers as r', 'r.id', '=', 'attendance_entries.register_id')
            ->where('attendance_entries.student_id', $studentId)->whereBetween('r.date', [$from, $to])
            ->selectRaw('attendance_entries.status, count(*) as n')->groupBy('attendance_entries.status')->pluck('n', 'status');

        return self::summarise($rows);
    }

    /** @param Collection<string,int>|array<string,int> $counts */
    public static function summarise($counts): array
    {
        $counts = collect(config('academic.attendance.statuses'))->map(fn ($l, $k) => (int) ($counts[$k] ?? 0));
        $total = $counts->sum();
        $weighted = $counts->map(fn ($n, $k) => $n * config("academic.attendance.weights.{$k}"))->sum();

        return ['counts' => $counts->all(), 'total' => $total, 'percent' => $total ? round($weighted * 100 / $total, 2) : null];
    }

    /** Every active section of a session on a date: marked or not, present and absent. */
    public function daySummary(string $sessionId, string $date, ?array $sectionIds = null): Collection
    {
        $sections = Section::with('level')->where('session_id', $sessionId)->where('is_active', true)
            ->when($sectionIds !== null, fn ($q) => $q->whereIn('id', $sectionIds))->get()->sortBy(fn ($s) => [$s->level->sequence, $s->name]);
        $registers = AttendanceRegister::whereIn('section_id', $sections->pluck('id'))->whereDate('date', $date)->get()->groupBy('section_id');
        $counts = AttendanceEntry::query()->whereIn('register_id', $registers->flatten()->pluck('id'))
            ->selectRaw('register_id, status, count(*) as n')->groupBy('register_id', 'status')->get()->groupBy('register_id');

        return $sections->values()->map(function ($s) use ($registers, $counts) {
            $regs = $registers[$s->id] ?? collect();
            $c = collect();
            foreach ($regs as $r) {
                foreach ($counts[$r->id] ?? [] as $row) {
                    $c[$row->status] = ($c[$row->status] ?? 0) + $row->n;
                }
            }

            return ['section' => $s, 'marked' => $regs->isNotEmpty(), 'registers' => $regs->count(), 'strength' => $s->activeCount()] + self::summarise($c);
        });
    }

    /** Student × day grid for a month. Daily mode: status letter. Period mode: "present periods/total". */
    public function monthlyRegister(Section $section, string $month): array
    {
        $start = Carbon::parse($month.'-01');
        $end = $start->copy()->endOfMonth()->min(today());
        $days = [];
        for ($d = $start->copy(); $d->lte($start->copy()->endOfMonth()); $d->addDay()) {
            $days[] = $d->format('Y-m-d');
        }
        $registers = AttendanceRegister::where('section_id', $section->id)->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])->get();
        $entries = AttendanceEntry::whereIn('register_id', $registers->pluck('id'))->get()->groupBy('student_id');
        $dateOf = $registers->mapWithKeys(fn ($r) => [$r->id => $r->date->format('Y-m-d')]);
        $letters = ['present' => 'P', 'absent' => 'A', 'late' => 'L', 'half_day' => 'H', 'leave' => 'LV'];

        $students = $section->enrollments()->with('student')->where('joined_on', '<=', $end->format('Y-m-d'))
            ->where(fn ($q) => $q->where('status', 'active')->orWhere('left_on', '>=', $start->format('Y-m-d')))->get()
            ->sortBy(fn ($e) => [$e->roll_no ?? 9999, $e->student->first_name]);

        $rows = $students->map(function ($e) use ($entries, $dateOf, $letters) {
            $mine = ($entries[$e->student_id] ?? collect())->groupBy(fn ($x) => $dateOf[$x->register_id]);
            $cells = $mine->map(fn ($list) => $list->count() === 1 ? $letters[$list->first()->status]
                : $list->filter(fn ($x) => in_array($x->status, ['present', 'late'], true))->count().'/'.$list->count());

            return ['enrollment' => $e, 'student' => $e->student, 'cells' => $cells->all(),
                'summary' => self::summarise(($entries[$e->student_id] ?? collect())->countBy('status'))];
        })->values();

        return ['days' => $days, 'marked_days' => $registers->map(fn ($r) => $r->date->format('Y-m-d'))->unique()->values()->all(), 'rows' => $rows];
    }

    /** Students below the threshold between two dates. */
    public function lowAttendance(string $from, string $to, ?float $threshold = null, ?array $sectionIds = null): Collection
    {
        $threshold ??= (float) Institution::profile()->low_attendance_percent;
        $q = AttendanceEntry::query()->join('attendance_registers as r', 'r.id', '=', 'attendance_entries.register_id')
            ->whereBetween('r.date', [$from, $to])
            ->when($sectionIds !== null, fn ($q) => $q->whereIn('r.section_id', $sectionIds))
            ->selectRaw('attendance_entries.student_id, attendance_entries.status, count(*) as n')
            ->groupBy('attendance_entries.student_id', 'attendance_entries.status')->get()->groupBy('student_id');

        $students = Student::with('currentEnrollment.section.level', 'guardians')->whereIn('id', $q->keys())->get()->keyBy('id');

        return $q->map(fn ($rows, $id) => ['student' => $students[$id] ?? null] + self::summarise($rows->pluck('n', 'status')))
            ->filter(fn ($r) => $r['student'] && $r['percent'] !== null && $r['percent'] < $threshold)
            ->sortBy('percent')->values();
    }
}
