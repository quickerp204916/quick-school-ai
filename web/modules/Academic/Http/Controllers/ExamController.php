<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\AcademicTerm;
use Modules\Academic\Models\Exam;
use Modules\Academic\Models\ExamPaper;
use Modules\Academic\Models\GradingScheme;
use Modules\Academic\Models\Mark;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\Subject;
use Modules\Academic\Services\ExamService;
use Modules\Academic\Support\Csv;
use Modules\Institution\Models\AcademicSession;

class ExamController extends Controller
{
    public function __construct(private readonly ExamService $exams)
    {
    }

    public function index(): View
    {
        $session = $this->session();
        $exams = Exam::with('term')->withCount('papers')->where('session_id', $session->id)->orderBy('starts_on')->get();

        // Papers this teacher enters marks for.
        $mine = collect();
        if (! $this->member()->seesAllSections()) {
            $mine = ExamPaper::with(['exam', 'section.level', 'subject'])->whereHas('exam', fn ($q) => $q->where('session_id', $session->id)->where('status', 'open'))
                ->get()->filter(fn ($p) => $this->member()->teaches($p->section, $p->subject_id));
        }

        return view('academic::exams.index', ['exams' => $exams, 'session' => $session, 'mine' => $mine, 'sessions' => AcademicSession::orderByDesc('starts_on')->get()]);
    }

    public function create(): View
    {
        return view('academic::exams.form', ['exam' => new Exam(['session_id' => $this->session()->id, 'type' => 'term']), ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $exam = $this->exams->saveExam($request->all());

        return $this->done('Exam created. Now add papers.', route('app.exams.show', $exam));
    }

    public function show(Exam $exam): View
    {
        $exam->load(['term', 'gradingScheme', 'session']);
        $papers = ExamPaper::with(['section.level', 'subject'])->withCount(['marks as entered' => fn ($q) => $q->where(fn ($w) => $w->whereNotNull('marks')->orWhere('is_absent', true))])
            ->where('exam_id', $exam->id)->get()->sortBy(fn ($p) => [$p->section->level->sequence, $p->section->name, $p->subject->name])->groupBy('section_id');

        return view('academic::exams.show', ['exam' => $exam, 'papers' => $papers,
            'sections' => Section::with('level')->where('session_id', $exam->session_id)->where('is_active', true)->get()->sortBy(fn ($s) => [$s->level->sequence, $s->name]),
            'subjects' => Subject::where('is_active', true)->orderBy('name')->get(),
            'strengths' => Section::withCount(['activeEnrollments as strength'])->where('session_id', $exam->session_id)->pluck('strength', 'id')]);
    }

    public function edit(Exam $exam): View
    {
        return view('academic::exams.form', ['exam' => $exam, ...$this->options()]);
    }

    public function update(Request $request, Exam $exam): RedirectResponse
    {
        $this->exams->saveExam($request->all(), $exam);

        return $this->done('Exam saved.', route('app.exams.show', $exam));
    }

    public function addPapers(Request $request, Exam $exam): RedirectResponse
    {
        $n = $this->exams->addPapers($exam, $request->all());

        return $this->done("{$n} papers added.");
    }

    public function updatePaper(Request $request, ExamPaper $paper): RedirectResponse
    {
        $this->exams->updatePaper($paper, $request->all());

        return $this->done('Paper saved.');
    }

    public function deletePaper(ExamPaper $paper): RedirectResponse
    {
        $this->exams->deletePaper($paper);

        return $this->done('Paper deleted.');
    }

    public function status(Request $request, Exam $exam): RedirectResponse
    {
        $this->exams->setStatus($exam, (string) $request->input('status'));

        return $this->done(['open' => 'Marks entry is open.', 'published' => 'Results published.', 'draft' => 'Back to draft.'][$exam->status]);
    }

    public function marks(ExamPaper $paper): View
    {
        $paper->load(['exam', 'section.level', 'subject']);
        $this->member()->mustTeach($paper->section, $paper->subject_id);

        return view('academic::exams.marks', ['paper' => $paper, 'rows' => $this->exams->sheet($paper)]);
    }

    public function saveMarks(Request $request, ExamPaper $paper): RedirectResponse
    {
        $n = $this->exams->enterMarks($paper, (array) $request->input('entries', []));

        return $this->done("Marks saved for {$n} students.");
    }

    public function analysis(Request $request, Exam $exam)
    {
        $sections = Section::with('level')->whereIn('id', ExamPaper::where('exam_id', $exam->id)->distinct()->pluck('section_id'))
            ->when($this->member()->visibleSectionIds() !== null, fn ($q) => $q->whereIn('id', $this->member()->visibleSectionIds()))
            ->get()->sortBy(fn ($s) => [$s->level->sequence, $s->name])->values();
        $section = $request->filled('section') ? $sections->firstWhere('id', $request->section) : $sections->first();
        $data = $section ? $this->exams->analysis($exam, $section) : null;

        if ($section && $request->input('format') === 'csv') {
            $papers = ExamPaper::with('subject')->where('exam_id', $exam->id)->where('section_id', $section->id)->get()->sortBy('subject.name');
            $marks = Mark::whereIn('paper_id', $papers->pluck('id'))->get()->groupBy('student_id');
            $rows = $this->exams->roster($papers->first())->map(function ($e) use ($papers, $marks) {
                $m = ($marks[$e->student_id] ?? collect())->keyBy('paper_id');

                return array_merge([$e->roll_no, $e->student->admission_no, $e->student->fullName()],
                    $papers->map(fn ($p) => isset($m[$p->id]) ? ($m[$p->id]->is_absent ? 'AB' : $m[$p->id]->marks) : '')->all());
            })->all();

            return Csv::download('marks.csv', array_merge(['Roll', 'Admission no', 'Name'], $papers->map(fn ($p) => $p->subject->name.' /'.$p->max_marks)->all()), $rows);
        }

        return view('academic::exams.analysis', ['exam' => $exam, 'sections' => $sections, 'section' => $section, 'data' => $data]);
    }

    private function options(): array
    {
        return ['sessions' => AcademicSession::where('status', '!=', 'closed')->orderByDesc('starts_on')->get(),
            'terms' => AcademicTerm::orderBy('sequence')->get()->groupBy('session_id'), 'schemes' => GradingScheme::orderBy('name')->get()];
    }
}
