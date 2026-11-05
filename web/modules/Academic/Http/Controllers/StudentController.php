<?php

namespace Modules\Academic\Http\Controllers;

use App\Core\Support\IndianStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\Certificate;
use Modules\Academic\Models\CertificateTemplate;
use Modules\Academic\Models\Enrollment;
use Modules\Academic\Models\GradeLevel;
use Modules\Academic\Models\Guardian;
use Modules\Academic\Models\ReportCard;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\Student;
use Modules\Academic\Models\StudentTransfer;
use Modules\Academic\Services\AttendanceService;
use Modules\Academic\Services\StudentService;
use Modules\Academic\Services\TransferService;
use Modules\Academic\Support\Csv;
use Modules\Institution\Models\Branch;

class StudentController extends Controller
{
    public function __construct(private readonly StudentService $students)
    {
    }

    public function index(Request $request)
    {
        $session = $this->session();
        $visible = $this->member()->visibleSectionIds();
        $status = $request->input('status', 'active');

        $q = Student::query()->with(['currentEnrollment.section.level', 'guardians'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($request->q)).'%';
                $q->where(fn ($w) => $w->whereLike('first_name', $term)->orWhereLike('last_name', $term)->orWhereLike('admission_no', $term)
                    ->orWhereHas('guardians', fn ($g) => $g->whereLike('phone', $term)->orWhereLike('name', $term)));
            })
            ->when($request->filled('section') || $request->filled('level') || $visible !== null, function ($q) use ($request, $visible, $session) {
                $q->whereHas('enrollments', fn ($e) => $e->where('status', 'active')->where('session_id', $session->id)
                    ->when($request->filled('section'), fn ($x) => $x->where('section_id', $request->section))
                    ->when($request->filled('level'), fn ($x) => $x->whereHas('section', fn ($s) => $s->where('grade_level_id', $request->level)))
                    ->when($visible !== null, fn ($x) => $x->whereIn('section_id', $visible)));
            })
            ->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->gender))
            ->orderBy('first_name')->orderBy('last_name');

        if ($request->input('format') === 'csv') {
            $rows = $q->get()->map(fn ($s) => [$s->admission_no, $s->fullName(), ucfirst($s->gender), $s->date_of_birth->format('Y-m-d'),
                $s->currentEnrollment?->section?->label(), $s->currentEnrollment?->roll_no, $s->primaryGuardian()?->name, $s->primaryGuardian()?->phone,
                $s->category ? config('academic.categories.'.$s->category) : null, $s->status])->all();

            return Csv::download('students.csv', ['Admission no', 'Name', 'Gender', 'Date of birth', 'Section', 'Roll no', 'Guardian', 'Phone', 'Category', 'Status'], $rows);
        }

        return view('academic::students.index', ['students' => $q->paginate(40)->withQueryString(), 'session' => $session,
            'sections' => Section::with('level')->where('session_id', $session->id)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->get()->sortBy(fn ($s) => [$s->level->sequence, $s->name]),
            'levels' => GradeLevel::orderBy('sequence')->get()]);
    }

    public function create(Request $request): View
    {
        $session = $this->session();

        return view('academic::students.create', ['states' => IndianStates::ALL, 'presetSection' => $request->input('section'),
            'sections' => Section::with('level', 'branch')->withCount(['activeEnrollments as strength'])->where('session_id', $session->id)->where('is_active', true)->get()->sortBy(fn ($s) => [$s->level->sequence, $s->name])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $g = collect($request->input('guardians', []))->filter(fn ($x) => trim((string) ($x['name'] ?? '')) !== '' || trim((string) ($x['phone'] ?? '')) !== '')->values()->all();
        $files = AdmissionController::files($request);
        $student = \Illuminate\Support\Facades\DB::transaction(function () use ($g, $request, $files) {
            $student = $this->students->create(['guardians' => $g] + $request->all());
            foreach ($files as $type => $file) {
                app(\Modules\Academic\Services\DocumentService::class)->upload($student, $type, $file, "uploads.{$type}");
            }

            return $student;
        });

        return $this->done("Admitted. Admission number {$student->admission_no}.", route('app.students.show', $student));
    }

    public function show(Student $student, AttendanceService $attendance): View
    {
        $this->member()->mustSeeStudent($student);
        $student->load(['guardians', 'branch', 'enrollments.section.level', 'enrollments.session']);
        $current = $student->enrollments->firstWhere('status', 'active');
        $att = $current ? $attendance->studentSummary($student->id, $current->session->starts_on->format('Y-m-d'), today()->format('Y-m-d')) : null;

        return view('academic::students.show', [
            's' => $student, 'current' => $current, 'attendance' => $att,
            'documents' => $student->documents()->get(),
            'reportCards' => ReportCard::where('student_id', $student->id)->orderByDesc('published_at')->get(),
            'certificates' => Certificate::where('student_id', $student->id)->orderByDesc('issued_on')->get(),
            'transfers' => StudentTransfer::with(['fromSection.level', 'toSection.level'])->where('student_id', $student->id)->orderByDesc('effective_on')->get(),
            'templates' => CertificateTemplate::where('is_active', true)->orderBy('name')->get(),
            'moveTargets' => $current ? Section::with('level', 'branch')->where('session_id', $current->session_id)->where('is_active', true)->whereKeyNot($current->section_id)->get()->sortBy(fn ($s) => [$s->level->sequence, $s->name]) : collect(),
        ]);
    }

    public function edit(Student $student): View
    {
        return view('academic::students.edit', ['s' => $student, 'states' => IndianStates::ALL]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $this->students->update($student, $request->all());

        return $this->done('Student record saved.', route('app.students.show', $student));
    }

    public function addGuardian(Request $request, Student $student): RedirectResponse
    {
        $this->students->linkGuardian($student, $request->all());

        return $this->done('Guardian added.');
    }

    public function updateGuardian(Request $request, Guardian $guardian): RedirectResponse
    {
        $this->students->updateGuardian($guardian, $request->all());

        return $this->done('Guardian saved.');
    }

    public function primaryGuardian(Student $student, Guardian $guardian): RedirectResponse
    {
        $this->students->setPrimaryGuardian($student, $guardian);

        return $this->done("{$guardian->name} is now the primary contact.");
    }

    public function removeGuardian(Student $student, Guardian $guardian): RedirectResponse
    {
        $this->students->unlinkGuardian($student, $guardian);

        return $this->done('Guardian removed from this student.');
    }

    public function inviteGuardian(Guardian $guardian): RedirectResponse
    {
        $this->students->inviteGuardian($guardian);

        return $this->done("App invitation emailed to {$guardian->email}.");
    }

    public function inviteStudent(Student $student): RedirectResponse
    {
        $this->students->inviteStudent($student);

        return $this->done("App invitation emailed to {$student->email}.");
    }

    public function move(Request $request, Student $student, TransferService $transfers): RedirectResponse
    {
        $t = $transfers->move($student, $request->all());

        return $this->done($t->type === 'branch' ? 'Branch transfer recorded.' : 'Section changed.');
    }

    public function leave(Request $request, Student $student, TransferService $transfers): RedirectResponse
    {
        $transfers->leave($student, $request->all());

        return $this->done('Leaving recorded. You can now issue a transfer certificate.');
    }

    public function roll(Request $request, Enrollment $enrollment): RedirectResponse
    {
        $request->validate(['roll_no' => ['nullable', 'integer', 'between:1,9999']]);
        $this->students->setRollNumber($enrollment, $request->filled('roll_no') ? (int) $request->roll_no : null);

        return $this->done('Roll number saved.');
    }
}
