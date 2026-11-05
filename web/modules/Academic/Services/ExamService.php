<?php

namespace Modules\Academic\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Models\AcademicTerm;
use Modules\Academic\Models\Enrollment;
use Modules\Academic\Models\Exam;
use Modules\Academic\Models\ExamPaper;
use Modules\Academic\Models\GradingScheme;
use Modules\Academic\Models\Mark;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\SectionSubject;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Academic\Support\Rules;
use Modules\Institution\Models\AcademicSession;

/** Exams, their papers (one per section and subject), marks entry and result analysis. */
final class ExamService
{
    public function __construct(private readonly CurrentMember $member)
    {
    }

    public function saveExam(array $data, ?Exam $exam = null): Exam
    {
        $sessionId = $data['session_id'] ?? null;
        $d = Rules::check($data, [
            'session_id' => ['required', Rules::exists('academic_sessions')],
            'term_id' => ['nullable', Rules::exists('academic_terms', ['session_id' => $sessionId])],
            'name' => ['required', 'string', 'max:80', Rules::unique('exams', 'name', $exam?->id, ['session_id' => $sessionId])],
            'type' => ['required', Rule::in(array_keys(config('academic.exam_types')))],
            'grading_scheme_id' => ['nullable', Rules::exists('grading_schemes')],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ], ['term_id.exists' => 'Choose a term of this session.', 'name.unique' => 'An exam with this name already exists in this session.']);

        if ($exam && $exam->status === 'published') {
            throw new AcademicRuleException('Unpublish the exam before changing it.');
        }
        $session = AcademicSession::findOrFail($d['session_id']);
        if (! $session->contains($d['starts_on']) || (! empty($d['ends_on']) && ! $session->contains($d['ends_on']))) {
            throw new AcademicRuleException("Exam dates must fall within session {$session->name}.", 'starts_on');
        }
        if ($exam && $exam->papers()->exists() && $exam->session_id !== $d['session_id']) {
            throw new AcademicRuleException('This exam has papers, so its session cannot change.', 'session_id');
        }
        $d['grading_scheme_id'] ??= GradingScheme::where('is_default', true)->value('id') ?? GradingScheme::value('id');

        $exam ??= new Exam(['status' => 'draft']);
        $exam->fill($d)->save();
        Audit::record('exam.saved', $exam, ['name' => $exam->name]);

        return $exam;
    }

    /** Creates papers for every chosen section and subject offered in it. Existing papers are left alone. */
    public function addPapers(Exam $exam, array $data): int
    {
        $d = Rules::check($data, [
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => [Rules::exists('sections', ['session_id' => $exam->session_id])],
            'subject_ids' => ['nullable', 'array'],
            'subject_ids.*' => [Rules::exists('subjects')],
            'max_marks' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'pass_marks' => ['required', 'numeric', 'min:0', 'lte:max_marks'],
            'exam_date' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'between:5,600'],
        ], ['section_ids.*.exists' => 'Choose sections from the exam’s session.', 'pass_marks.lte' => 'Pass marks cannot be more than maximum marks.']);
        $this->editable($exam);

        $made = 0;
        DB::transaction(function () use ($exam, $d, &$made) {
            foreach ($d['section_ids'] as $sectionId) {
                $offered = SectionSubject::where('section_id', $sectionId)->pluck('subject_id');
                $subjects = empty($d['subject_ids']) ? $offered : $offered->intersect($d['subject_ids']);
                foreach ($subjects as $subjectId) {
                    $paper = ExamPaper::firstOrCreate(['exam_id' => $exam->id, 'section_id' => $sectionId, 'subject_id' => $subjectId], [
                        'max_marks' => $d['max_marks'], 'pass_marks' => $d['pass_marks'], 'exam_date' => $d['exam_date'] ?? null,
                        'starts_at' => $d['starts_at'] ?? null, 'duration_minutes' => $d['duration_minutes'] ?? null,
                    ]);
                    $made += $paper->wasRecentlyCreated ? 1 : 0;
                }
            }
        });
        if ($made === 0) {
            throw new AcademicRuleException('No new papers: the chosen subjects are not taught in these sections, or papers already exist.', 'subject_ids');
        }

        return $made;
    }

    public function updatePaper(ExamPaper $paper, array $data): ExamPaper
    {
        $d = Rules::check($data, [
            'max_marks' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'pass_marks' => ['required', 'numeric', 'min:0', 'lte:max_marks'],
            'exam_date' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'between:5,600'],
        ]);
        $this->editable($paper->exam);
        $highest = (float) Mark::where('paper_id', $paper->id)->max('marks');
        if ($highest > (float) $d['max_marks']) {
            throw new AcademicRuleException("A student already has {$highest} marks, more than the new maximum.", 'max_marks');
        }
        $paper->update($d);

        return $paper;
    }

    public function deletePaper(ExamPaper $paper): void
    {
        $this->editable($paper->exam);
        if (Mark::where('paper_id', $paper->id)->exists()) {
            throw new AcademicRuleException('Marks have been entered for this paper, so it cannot be deleted.');
        }
        $paper->delete();
    }

    /** draft → open (marks entry) → published; published → open to correct marks. */
    public function setStatus(Exam $exam, string $status): Exam
    {
        $allowed = ['draft' => ['open'], 'open' => ['draft', 'published'], 'published' => ['open']][$exam->status] ?? [];
        if (! in_array($status, $allowed, true)) {
            throw new AcademicRuleException("An exam that is {$exam->status} cannot become {$status}.", 'status');
        }
        if ($status === 'open' && ! $exam->papers()->exists()) {
            throw new AcademicRuleException('Add papers before opening marks entry.');
        }
        if ($status === 'draft' && Mark::whereIn('paper_id', $exam->papers()->pluck('id'))->exists()) {
            throw new AcademicRuleException('Marks have been entered, so the exam cannot go back to draft.');
        }
        if ($status === 'published' && ($incomplete = $this->incompletePapers($exam))->isNotEmpty()) {
            $list = $incomplete->take(5)->map(fn ($p) => "{$p['paper']->section->label()} {$p['paper']->subject->name} ({$p['missing']} missing)")->implode('; ');
            throw new AcademicRuleException('Marks are missing: '.$list.($incomplete->count() > 5 ? '; and more' : '').'. Enter marks or mark students absent.');
        }
        $exam->update(['status' => $status, 'published_at' => $status === 'published' ? now() : null]);
        Audit::record('exam.status_changed', $exam, ['status' => $status]);

        return $exam;
    }

    /** Papers where some enrolled students have no mark and are not marked absent. */
    public function incompletePapers(Exam $exam): Collection
    {
        return $exam->papers()->with(['section.level', 'subject'])->get()->map(function (ExamPaper $p) {
            $roster = $this->roster($p)->pluck('student_id');
            $done = Mark::where('paper_id', $p->id)->where(fn ($q) => $q->whereNotNull('marks')->orWhere('is_absent', true))->pluck('student_id');

            return ['paper' => $p, 'missing' => $roster->diff($done)->count()];
        })->filter(fn ($r) => $r['missing'] > 0)->values();
    }

    /** Students who sit a paper: enrolled in the section on the exam date (or now, if no date). */
    public function roster(ExamPaper $paper): Collection
    {
        $section = $paper->section ?? Section::find($paper->section_id);
        $date = ($paper->exam_date ?? $paper->exam->starts_on)->format('Y-m-d');
        $onDate = StudentService::rosterOn($section, min($date, today()->format('Y-m-d')));

        return $onDate->isNotEmpty() ? $onDate : Enrollment::with('student')->where('section_id', $section->id)->where('status', 'active')->get();
    }

    public function sheet(ExamPaper $paper): array
    {
        $marks = Mark::where('paper_id', $paper->id)->get()->keyBy('student_id');

        return $this->roster($paper)->map(fn ($e) => ['student' => $e->student, 'roll_no' => $e->roll_no, 'mark' => $marks[$e->student_id] ?? null])->all();
    }

    /**
     * @param array<string, array{marks?: ?string, absent?: bool, remarks?: ?string}> $entries keyed by student id; blank marks clear the entry
     */
    public function enterMarks(ExamPaper $paper, array $entries): int
    {
        $paper->loadMissing('exam', 'section', 'subject');
        if ($paper->exam->status !== 'open') {
            throw new AcademicRuleException($paper->exam->status === 'published' ? 'Results are published. Unpublish the exam to correct marks.' : 'Marks entry has not been opened for this exam.');
        }
        $this->member->mustTeach($paper->section, $paper->subject_id);

        $max = (float) $paper->max_marks;
        $roster = $this->roster($paper)->pluck('student_id')->all();
        $errors = [];
        foreach ($entries as $studentId => $e) {
            $raw = trim((string) ($e['marks'] ?? ''));
            if (! in_array($studentId, $roster, true)) {
                $errors["entries.{$studentId}"] = 'This student does not sit this paper.';
            } elseif (! empty($e['absent']) && $raw !== '') {
                $errors["entries.{$studentId}"] = 'A student marked absent cannot have marks.';
            } elseif ($raw !== '' && (! preg_match('/^\d{1,4}(\.\d{1,2})?$/', $raw) || (float) $raw > $max)) {
                $errors["entries.{$studentId}"] = "Enter marks from 0 to {$max}, with up to 2 decimals.";
            }
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        $saved = 0;
        DB::transaction(function () use ($paper, $entries, &$saved) {
            foreach ($entries as $studentId => $e) {
                $raw = trim((string) ($e['marks'] ?? ''));
                $absent = ! empty($e['absent']);
                if ($raw === '' && ! $absent) {
                    Mark::where('paper_id', $paper->id)->where('student_id', $studentId)->delete();

                    continue;
                }
                Mark::updateOrCreate(['paper_id' => $paper->id, 'student_id' => $studentId], [
                    'marks' => $absent ? null : $raw, 'is_absent' => $absent, 'remarks' => $e['remarks'] ?? null, 'entered_by' => $this->member->userId(),
                ]);
                $saved++;
            }
        });

        return $saved;
    }

    /** Per subject: average, highest, lowest, pass rate; and the top students by total. */
    public function analysis(Exam $exam, Section $section): array
    {
        $papers = ExamPaper::with('subject')->where('exam_id', $exam->id)->where('section_id', $section->id)->get();
        $marks = Mark::whereIn('paper_id', $papers->pluck('id'))->get()->groupBy('paper_id');
        $subjects = $papers->map(function ($p) use ($marks) {
            $m = $marks[$p->id] ?? collect();
            $sat = $m->whereNotNull('marks');
            $vals = $sat->map(fn ($x) => (float) $x->marks);

            return ['paper' => $p, 'subject' => $p->subject, 'entered' => $m->count(), 'absent' => $m->where('is_absent', true)->count(),
                'average' => $vals->isNotEmpty() ? round($vals->avg(), 2) : null, 'highest' => $vals->max(), 'lowest' => $vals->min(),
                'passed' => $vals->filter(fn ($v) => $v >= (float) $p->pass_marks)->count(),
                'pass_percent' => $m->count() ? round($vals->filter(fn ($v) => $v >= (float) $p->pass_marks)->count() * 100 / $m->count(), 1) : null];
        })->sortBy(fn ($r) => $r['subject']->name)->values();

        $maxTotal = (float) $papers->sum('max_marks');
        $totals = $marks->flatten()->groupBy('student_id')->map(fn ($rows) => $rows->sum(fn ($r) => (float) $r->marks));
        $students = \Modules\Academic\Models\Student::whereIn('id', $totals->keys())->get()->keyBy('id');
        $toppers = $totals->sortDesc()->take(5)->map(fn ($t, $id) => ['student' => $students[$id], 'total' => $t,
            'percent' => $maxTotal > 0 ? round($t * 100 / $maxTotal, 2) : null])->values();

        return ['subjects' => $subjects, 'toppers' => $toppers, 'max_total' => $maxTotal];
    }

    private function editable(Exam $exam): void
    {
        if ($exam->status === 'published') {
            throw new AcademicRuleException('Results are published. Unpublish the exam first.');
        }
    }
}
