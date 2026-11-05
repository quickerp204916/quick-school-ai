<?php

namespace Modules\Academic\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Models\Homework;
use Modules\Academic\Models\HomeworkSubmission;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\SectionSubject;
use Modules\Academic\Models\Student;
use Modules\Academic\Support\CurrentMember;
use Modules\Academic\Support\Rules;

/** Homework set by teachers, submissions by students (app), checking by teachers. */
final class HomeworkService
{
    public function __construct(private readonly CurrentMember $member)
    {
    }

    public function save(array $data, ?Homework $homework = null): Homework
    {
        $d = Rules::check($data, [
            'section_id' => ['required', Rules::exists('sections', ['is_active' => true])],
            'subject_id' => ['required', Rules::exists('subjects')],
            'title' => ['required', 'string', 'max:150'],
            'details' => ['nullable', 'string', 'max:5000'],
            'assigned_on' => ['required', 'date'],
            'due_on' => ['required', 'date', 'after_or_equal:assigned_on'],
        ], ['due_on.after_or_equal' => 'The due date cannot be before the date it is set.']);

        $section = Section::with('session')->findOrFail($d['section_id']);
        if (! SectionSubject::where('section_id', $section->id)->where('subject_id', $d['subject_id'])->exists()) {
            throw new AcademicRuleException('This subject is not taught in this section.', 'subject_id');
        }
        $this->member->mustTeach($section, $d['subject_id']);
        if (! $section->session->contains($d['due_on'])) {
            throw new AcademicRuleException("The due date is outside session {$section->session->name}.", 'due_on');
        }
        if ($homework && $homework->status === 'cancelled') {
            throw new AcademicRuleException('Cancelled homework cannot be edited.');
        }
        $homework ??= new Homework(['status' => 'published', 'assigned_by' => $this->member->staff()?->id]);
        $homework->fill($d)->save();

        return $homework;
    }

    public function cancel(Homework $homework): void
    {
        $this->member->mustTeach($homework->section, $homework->subject_id);
        $homework->update(['status' => 'cancelled']);
    }

    /** Student submits (from the app). After the due date it is recorded as late. */
    public function submit(Homework $homework, Student $student, ?string $content): HomeworkSubmission
    {
        if ($homework->status !== 'published') {
            throw new AcademicRuleException('This homework was cancelled.');
        }
        $enrolled = StudentService::rosterOn($homework->section, today()->format('Y-m-d'))->contains('student_id', $student->id);
        if (! $enrolled) {
            throw new AcademicRuleException('This homework is not for this student.');
        }
        $existing = HomeworkSubmission::where('homework_id', $homework->id)->where('student_id', $student->id)->first();
        if ($existing && $existing->status === 'checked') {
            throw new AcademicRuleException('This homework has already been checked.');
        }

        return HomeworkSubmission::updateOrCreate(['homework_id' => $homework->id, 'student_id' => $student->id], [
            'content' => $content, 'submitted_at' => now(),
            'status' => today()->gt($homework->due_on) ? 'late' : 'submitted',
        ]);
    }

    /**
     * Teacher records the outcome for several students at once.
     * @param array<string, array{status: string, grade?: ?string, remarks?: ?string}> $rows keyed by student id
     */
    public function check(Homework $homework, array $rows): int
    {
        $this->member->mustTeach($homework->section, $homework->subject_id);
        Rules::check(['rows' => $rows], [
            'rows' => ['required', 'array'],
            'rows.*.status' => ['required', Rule::in(['pending', 'submitted', 'late', 'checked', 'redo'])],
            'rows.*.grade' => ['nullable', 'string', 'max:10'],
            'rows.*.remarks' => ['nullable', 'string', 'max:200'],
        ]);
        $roster = StudentService::rosterOn($homework->section, $homework->assigned_on->format('Y-m-d'))->pluck('student_id')
            ->merge(StudentService::rosterOn($homework->section, today()->format('Y-m-d'))->pluck('student_id'))->unique();

        $n = 0;
        DB::transaction(function () use ($homework, $rows, $roster, &$n) {
            foreach ($rows as $studentId => $r) {
                if (! $roster->contains($studentId)) {
                    throw new AcademicRuleException('A student in the list is not in this section.');
                }
                if ($r['status'] === 'pending') {
                    HomeworkSubmission::where('homework_id', $homework->id)->where('student_id', $studentId)->delete();

                    continue;
                }
                $sub = HomeworkSubmission::firstOrNew(['homework_id' => $homework->id, 'student_id' => $studentId]);
                $sub->fill(['status' => $r['status'], 'grade' => $r['grade'] ?? null, 'remarks' => $r['remarks'] ?? null,
                    'submitted_at' => $sub->submitted_at ?? (in_array($r['status'], ['submitted', 'late', 'checked'], true) ? now() : null),
                    'checked_by' => in_array($r['status'], ['checked', 'redo'], true) ? $this->member->userId() : $sub->checked_by,
                    'checked_at' => in_array($r['status'], ['checked', 'redo'], true) ? now() : $sub->checked_at])->save();
                $n++;
            }
        });

        return $n;
    }

    /** Submission status for every student in the section. */
    public function tracker(Homework $homework): array
    {
        $subs = HomeworkSubmission::where('homework_id', $homework->id)->get()->keyBy('student_id');
        $rows = StudentService::rosterOn($homework->section, min(today()->format('Y-m-d'), $homework->due_on->format('Y-m-d')))
            ->map(fn ($e) => ['student' => $e->student, 'roll_no' => $e->roll_no, 'submission' => $subs[$e->student_id] ?? null]);
        $done = $rows->filter(fn ($r) => in_array($r['submission']?->status, ['submitted', 'late', 'checked'], true))->count();

        return ['rows' => $rows, 'done' => $done, 'total' => $rows->count(), 'percent' => $rows->count() ? round($done * 100 / $rows->count(), 1) : null];
    }
}
