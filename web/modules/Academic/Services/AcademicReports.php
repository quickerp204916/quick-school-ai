<?php

namespace Modules\Academic\Services;

use Illuminate\Support\Collection;
use Modules\Academic\Models\Certificate;
use Modules\Academic\Models\Enrollment;
use Modules\Academic\Models\Homework;
use Modules\Academic\Models\HomeworkSubmission;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\Student;

/** Reports that are not owned by one service: strength, admission register, homework, certificates. */
final class AcademicReports
{
    /** Students per section by gender, against capacity. */
    public function strength(string $sessionId, ?string $branchId = null): Collection
    {
        $sections = Section::with(['level', 'branch', 'classTeacher'])->where('session_id', $sessionId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->get()->sortBy(fn ($s) => [$s->level->sequence, $s->name]);
        $counts = Enrollment::query()->join('students', 'students.id', '=', 'enrollments.student_id')
            ->whereIn('enrollments.section_id', $sections->pluck('id'))->where('enrollments.status', 'active')
            ->selectRaw('enrollments.section_id, students.gender, count(*) as n')->groupBy('enrollments.section_id', 'students.gender')->get()
            ->groupBy('section_id');

        return $sections->values()->map(function ($s) use ($counts) {
            $c = ($counts[$s->id] ?? collect())->pluck('n', 'gender');
            $total = (int) $c->sum();

            return ['section' => $s, 'male' => (int) ($c['male'] ?? 0), 'female' => (int) ($c['female'] ?? 0), 'other' => (int) ($c['other'] ?? 0),
                'total' => $total, 'capacity' => $s->capacity, 'vacant' => $s->capacity !== null ? max(0, $s->capacity - $total) : null];
        });
    }

    /** Admission register: everyone admitted in a session, in admission-number order. */
    public function admissionRegister(string $sessionId): Collection
    {
        return Student::with(['guardians', 'enrollments.section.level'])->where('admitted_session_id', $sessionId)->orderBy('admission_no')->get();
    }

    /** Homework set and completion rate per section and subject. */
    public function homework(string $from, string $to, ?array $sectionIds = null): Collection
    {
        $hw = Homework::with(['section.level', 'subject'])->where('status', 'published')->whereBetween('due_on', [$from, $to])
            ->when($sectionIds !== null, fn ($q) => $q->whereIn('section_id', $sectionIds))->get();
        $subs = HomeworkSubmission::whereIn('homework_id', $hw->pluck('id'))->whereIn('status', ['submitted', 'late', 'checked'])
            ->selectRaw('homework_id, count(*) as n')->groupBy('homework_id')->pluck('n', 'homework_id');

        return $hw->groupBy(fn ($h) => $h->section_id.'|'.$h->subject_id)->map(function ($list) use ($subs) {
            $first = $list->first();
            $strength = $first->section->activeCount();
            $expected = $strength * $list->count();
            $done = $list->sum(fn ($h) => (int) ($subs[$h->id] ?? 0));

            return ['section' => $first->section, 'subject' => $first->subject, 'set' => $list->count(), 'expected' => $expected,
                'done' => $done, 'percent' => $expected ? round($done * 100 / $expected, 1) : null];
        })->sortBy(fn ($r) => [$r['section']->level->sequence, $r['section']->name, $r['subject']->name])->values();
    }

    public function certificates(string $from, string $to): Collection
    {
        return Certificate::with('student')->whereBetween('issued_on', [$from, $to])->orderBy('number')->get();
    }
}
