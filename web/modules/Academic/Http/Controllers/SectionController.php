<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\Enrollment;
use Modules\Academic\Models\GradeLevel;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\SectionSubject;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Models\Subject;
use Modules\Academic\Services\SetupService;
use Modules\Academic\Services\StudentService;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;

/** Sections of classes, or batches of courses. */
class SectionController extends Controller
{
    public function __construct(private readonly SetupService $setup)
    {
    }

    public function index(Request $request): View
    {
        $session = $this->session();
        $visible = $this->member()->visibleSectionIds();
        $sections = Section::with(['level.program', 'branch', 'classTeacher'])->withCount(['activeEnrollments as strength'])
            ->where('session_id', $session->id)
            ->when($request->filled('branch'), fn ($q) => $q->where('branch_id', $request->branch))
            ->when($request->filled('level'), fn ($q) => $q->where('grade_level_id', $request->level))
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->get()->sortBy(fn ($s) => [$s->level->program->sort_order, $s->level->sequence, $s->name]);

        return view('academic::sections.index', ['sections' => $sections, 'session' => $session, 'sessions' => AcademicSession::orderByDesc('starts_on')->get(),
            'branches' => Branch::orderBy('name')->get(), 'levels' => GradeLevel::with('program')->orderBy('sequence')->get()]);
    }

    public function create(): View
    {
        return view('academic::sections.form', ['section' => new Section(['session_id' => $this->session()->id, 'is_active' => true]), ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $s = $this->setup->saveSection($request->all());

        return $this->done('Created with the '.strtolower(\Modules\Academic\Support\Institution::term('level')).'’s subjects. Assign teachers below.', route('app.sections.show', $s));
    }

    public function show(Section $section): View
    {
        $this->member()->mustTeach($section);
        $section->load(['level', 'branch', 'session', 'classTeacher', 'subjects.subject', 'subjects.teacher']);

        return view('academic::sections.show', [
            'section' => $section,
            'enrollments' => Enrollment::with('student')->where('section_id', $section->id)->orderByRaw("status = 'active' desc")->get()
                ->sortBy(fn ($e) => [$e->status !== 'active', $e->roll_no ?? 9999, $e->student->first_name]),
            'teachers' => StaffMember::where('status', 'active')->where('is_teaching', true)->orderBy('name')->get(),
            'subjects' => Subject::where('is_active', true)->whereNotIn('id', $section->subjects->pluck('subject_id'))->orderBy('name')->get(),
        ]);
    }

    public function edit(Section $section): View
    {
        return view('academic::sections.form', ['section' => $section, ...$this->options()]);
    }

    public function update(Request $request, Section $section): RedirectResponse
    {
        $this->setup->saveSection($request->all(), $section);

        return $this->done('Saved.', route('app.sections.show', $section));
    }

    public function storeSubject(Request $request, Section $section): RedirectResponse
    {
        $this->setup->assignSubject($section, $request->all());

        return $this->done('Subject and teacher saved.');
    }

    public function destroySubject(SectionSubject $row): RedirectResponse
    {
        $this->setup->removeSubject($row);

        return $this->done('Subject removed from this section.');
    }

    public function rollNumbers(Request $request, Section $section, StudentService $students): RedirectResponse
    {
        $n = $students->assignRollNumbers($section, $request->input('order') === 'admission' ? 'admission' : 'name');

        return $this->done("Roll numbers 1 to {$n} assigned.");
    }

    private function options(): array
    {
        return ['sessions' => AcademicSession::where('status', '!=', 'closed')->orderByDesc('starts_on')->get(), 'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'levels' => GradeLevel::with('program')->where('is_active', true)->get()->sortBy(fn ($l) => [$l->program->sort_order, $l->sequence]),
            'teachers' => StaffMember::where('status', 'active')->where('is_teaching', true)->orderBy('name')->get()];
    }
}
