<?php

namespace Modules\Academic\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Models\Exam;
use Modules\Academic\Models\ExamPaper;
use Modules\Academic\Models\GradingScheme;
use Modules\Academic\Models\Mark;
use Modules\Academic\Models\ReportCard;
use Modules\Academic\Models\Section;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\Rules;

/**
 * Report cards from one or more published exams with weights (for example Half-yearly 40%, Annual 60%).
 *
 * Per subject: weighted percentage across exams, grade from the grading scheme, pass if it reaches the
 * weighted pass mark. Co-scholastic subjects get a grade only and do not count towards the total.
 * Overall: average of subject percentages (credit-weighted when subjects have credits), grade, GPA for
 * GPA schemes, pass if every subject is passed and the overall meets the scheme's pass percentage.
 * Rank: 1, 2, 2, 4 among students who passed.
 */
final class ReportCardService
{
    public function __construct(private readonly AttendanceService $attendance, private readonly ExamService $exams)
    {
    }

    /** @return array<string, float> exam id → weight */
    public static function weights(array $data): array
    {
        $d = Rules::check($data, [
            'exams' => ['required', 'array', 'min:1'],
            'exams.*' => ['nullable', 'numeric', 'between:0,100'],
        ], ['exams.required' => 'Choose at least one exam.']);
        $w = collect($d['exams'])->map(fn ($v) => (float) $v)->filter(fn ($v) => $v > 0);
        if ($w->isEmpty()) {
            throw new AcademicRuleException('Give at least one exam a weight above zero.', 'exams');
        }

        return $w->all();
    }

    /** @param array<string, float> $weights */
    public function compute(Section $section, array $weights): Collection
    {
        $section->loadMissing('session', 'level');
        $exams = Exam::with('gradingScheme.bands')->whereIn('id', array_keys($weights))->get()->keyBy('id');
        if ($exams->count() !== count($weights)) {
            throw new AcademicRuleException('One of the chosen exams was not found.', 'exams');
        }
        foreach ($exams as $exam) {
            if ($exam->session_id !== $section->session_id) {
                throw new AcademicRuleException("{$exam->name} belongs to another session.", 'exams');
            }
        }
        /** @var GradingScheme $scheme */
        $scheme = $exams->sortBy('starts_on')->last()->gradingScheme;

        $papers = ExamPaper::with('subject')->whereIn('exam_id', $exams->keys())->where('section_id', $section->id)->get();
        if ($papers->isEmpty()) {
            throw new AcademicRuleException('The chosen exams have no papers for this section.', 'exams');
        }
        $marks = Mark::whereIn('paper_id', $papers->pluck('id'))->get()->groupBy('student_id');
        $bySubject = $papers->groupBy('subject_id');
        $students = $papers->flatMap(fn ($p) => $this->exams->roster($p)->pluck('student'))->unique('id')->sortBy(fn ($s) => $s->first_name)->values();
        $from = $section->session->starts_on->format('Y-m-d');
        $to = min($section->session->ends_on->format('Y-m-d'), today()->format('Y-m-d'));

        $cards = $students->map(function ($student) use ($bySubject, $marks, $weights, $scheme, $exams, $from, $to) {
            $mine = ($marks[$student->id] ?? collect())->keyBy('paper_id');
            $rows = [];
            $incomplete = 0;
            foreach ($bySubject as $subjectPapers) {
                $subject = $subjectPapers->first()->subject;
                $sumW = $sumP = $sumPass = 0.0;
                $cells = [];
                foreach ($subjectPapers->sortBy(fn ($p) => $exams[$p->exam_id]->starts_on) as $p) {
                    $m = $mine[$p->id] ?? null;
                    $w = $weights[$p->exam_id];
                    $max = (float) $p->max_marks;
                    if (! $m || ($m->marks === null && ! $m->is_absent)) {
                        $incomplete++;
                    }
                    $pct = $m && $m->marks !== null ? (float) $m->marks * 100 / $max : 0.0;
                    $sumW += $w;
                    $sumP += $w * $pct;
                    $sumPass += $w * ((float) $p->pass_marks * 100 / $max);
                    $cells[] = ['exam_id' => $p->exam_id, 'exam' => $exams[$p->exam_id]->name, 'marks' => $m?->marks !== null ? (float) $m->marks : null,
                        'absent' => (bool) $m?->is_absent, 'max' => $max, 'percent' => round($pct, 2)];
                }
                $percent = round($sumP / $sumW, 2);
                $band = $scheme->bandFor($percent);
                $graded = $subject->isGradedOnly();
                $rows[] = [
                    'subject_id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name, 'type' => $subject->type,
                    'credits' => $subject->credits !== null ? (float) $subject->credits : null, 'exams' => $cells,
                    'percent' => $percent, 'grade' => $band?->grade, 'grade_point' => $band?->grade_point !== null ? (float) $band->grade_point : null,
                    'passed' => $graded ? null : $percent >= round($sumPass / $sumW, 2),
                ];
            }
            usort($rows, fn ($a, $b) => [$a['type'] === 'co_scholastic', $a['name']] <=> [$b['type'] === 'co_scholastic', $b['name']]);

            $scholastic = collect($rows)->where('type', '!=', 'co_scholastic');
            $useCredits = $scholastic->isNotEmpty() && $scholastic->every(fn ($r) => $r['credits'] > 0);
            $weightOf = fn ($r) => $useCredits ? $r['credits'] : 1.0;
            $totalW = $scholastic->sum($weightOf);
            $overall = $totalW > 0 ? round($scholastic->sum(fn ($r) => $r['percent'] * $weightOf($r)) / $totalW, 2) : 0.0;
            $gpa = $scheme->type === 'gpa' && $totalW > 0 ? round($scholastic->sum(fn ($r) => ($r['grade_point'] ?? 0) * $weightOf($r)) / $totalW, 2) : null;
            $failed = $scholastic->where('passed', false)->pluck('name')->values()->all();
            $passed = ! $failed && $overall >= (float) $scheme->pass_percent;
            $att = $this->attendance->studentSummary($student->id, $from, $to);

            return ['student' => $student, 'subjects' => $rows, 'percentage' => $overall, 'grade' => $scheme->bandFor($overall)?->grade,
                'gpa' => $gpa, 'result' => $passed ? 'pass' : 'fail', 'failed_subjects' => $failed, 'incomplete' => $incomplete,
                'attendance_percent' => $att['percent'], 'attendance' => $att, 'rank' => null];
        });

        // Competition ranking among students who passed.
        $rank = 0;
        $prev = null;
        $seen = 0;
        foreach ($cards->filter(fn ($c) => $c['result'] === 'pass')->sortByDesc('percentage') as $key => $c) {
            $seen++;
            if ($c['percentage'] !== $prev) {
                $rank = $seen;
                $prev = $c['percentage'];
            }
            $cards[$key] = array_merge($c, ['rank' => $rank]);
        }

        return $cards->values();
    }

    /** Saves report cards for every student in the section. Re-publishing the same title replaces them. */
    public function publish(Section $section, array $data): int
    {
        $weights = self::weights($data);
        $d = Rules::check($data, [
            'title' => ['required', 'string', 'max:80'],
            'remarks' => ['nullable', 'array'],
            'remarks.*' => ['nullable', 'string', 'max:500'],
        ]);
        $exams = Exam::whereIn('id', array_keys($weights))->get();
        if ($exams->contains(fn ($e) => $e->status !== 'published')) {
            throw new AcademicRuleException('Publish the results of every chosen exam first: '.$exams->where('status', '!=', 'published')->pluck('name')->implode(', ').'.', 'exams');
        }
        $cards = $this->compute($section, $weights);
        if ($cards->sum('incomplete') > 0) {
            throw new AcademicRuleException('Some marks are missing. Enter them or mark students absent before publishing.');
        }
        $examList = $exams->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'weight' => $weights[$e->id]])->values()->all();

        DB::transaction(function () use ($cards, $section, $d, $examList) {
            foreach ($cards as $c) {
                ReportCard::updateOrCreate(
                    ['student_id' => $c['student']->id, 'session_id' => $section->session_id, 'title' => $d['title']],
                    ['section_id' => $section->id, 'exams' => $examList,
                        'data' => ['subjects' => $c['subjects'], 'failed_subjects' => $c['failed_subjects'], 'attendance' => $c['attendance']],
                        'percentage' => $c['percentage'], 'grade' => $c['grade'], 'gpa' => $c['gpa'], 'rank' => $c['rank'], 'result' => $c['result'],
                        'attendance_percent' => $c['attendance_percent'], 'remarks' => $d['remarks'][$c['student']->id] ?? null,
                        'published_at' => now(), 'published_by' => request()->user()?->getAuthIdentifier()]
                );
            }
        });
        Audit::record('report_cards.published', $section, ['title' => $d['title'], 'count' => $cards->count()]);

        return $cards->count();
    }
}
