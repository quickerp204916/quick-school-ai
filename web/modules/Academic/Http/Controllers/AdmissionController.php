<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\AdmissionApplication;
use Modules\Academic\Models\GradeLevel;
use Modules\Academic\Models\Section;
use Modules\Academic\Services\AdmissionService;
use Modules\Academic\Support\Institution;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;

class AdmissionController extends Controller
{
    public function __construct(private readonly AdmissionService $admissions)
    {
    }

    public function index(Request $request): View
    {
        $session = $this->session();
        $apps = AdmissionApplication::with(['level', 'branch'])->where('session_id', $session->id)
            ->when($request->filled('stage'), fn ($q) => $q->where('stage', $request->stage))
            ->when($request->filled('level'), fn ($q) => $q->where('grade_level_id', $request->level))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->whereLike('first_name', '%'.$request->q.'%')
                ->orWhereLike('last_name', '%'.$request->q.'%')->orWhereLike('number', '%'.$request->q.'%')->orWhereLike('phone', '%'.$request->q.'%')))
            ->when($request->boolean('follow_up'), fn ($q) => $q->whereNotNull('follow_up_on')->where('follow_up_on', '<=', today())->whereNotIn('stage', ['admitted', 'rejected', 'withdrawn']))
            ->orderByDesc('created_at')->paginate(30)->withQueryString();

        return view('academic::admissions.index', ['apps' => $apps, 'session' => $session, 'funnel' => $this->admissions->funnel($session->id),
            'sessions' => AcademicSession::orderByDesc('starts_on')->get(), 'levels' => GradeLevel::orderBy('sequence')->get()]);
    }

    public function create(): View
    {
        return view('academic::admissions.form', ['app' => new AdmissionApplication(['session_id' => $this->session()->id, 'source' => 'walk_in', 'guardians' => [[], []]]), ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $files = self::files($request);
        $app = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $files) {
            $app = $this->admissions->create(self::clean($request), $request->input('stage') === 'applied' ? 'applied' : 'enquiry');
            foreach ($files as $type => $file) {
                app(\Modules\Academic\Services\DocumentService::class)->upload($app, $type, $file, "uploads.{$type}");
            }

            return $app;
        });

        return $this->done("Saved as {$app->number}.", route('app.admissions.show', $app));
    }

    public function show(AdmissionApplication $admission): View
    {
        $admission->load(['level', 'branch', 'session', 'student']);

        return view('academic::admissions.show', ['app' => $admission, 'uploads' => $admission->uploads()->get()->keyBy('type'),
            'sections' => Section::with('level')->withCount(['activeEnrollments as strength'])->where(['session_id' => $admission->session_id, 'grade_level_id' => $admission->grade_level_id, 'branch_id' => $admission->branch_id, 'is_active' => true])->get(),
            'required' => Institution::profile()->required_documents ?? []]);
    }

    public function edit(AdmissionApplication $admission): View
    {
        return view('academic::admissions.form', ['app' => $admission, ...$this->options()]);
    }

    public function update(Request $request, AdmissionApplication $admission): RedirectResponse
    {
        $this->admissions->update($admission, self::clean($request));

        return $this->done('Application saved.', route('app.admissions.show', $admission));
    }

    public function stage(Request $request, AdmissionApplication $admission): RedirectResponse
    {
        $this->admissions->move($admission, (string) $request->input('stage'), $request->input('reason'));

        return $this->done('Moved to '.str_replace('_', ' ', $admission->stage).'.');
    }

    public function documents(Request $request, AdmissionApplication $admission): RedirectResponse
    {
        $this->admissions->setDocuments($admission, (array) $request->input('documents', []));

        return $this->done('Documents updated.');
    }

    public function admit(Request $request, AdmissionApplication $admission): RedirectResponse
    {
        $student = $this->admissions->admit($admission, $request->all());

        return $this->done("Admitted. Admission number {$student->admission_no}.", route('app.students.show', $student));
    }

    /**
     * Optional files sent with the form as uploads[type]. All are checked before anything is saved.
     *
     * @return array<string, \Illuminate\Http\UploadedFile>
     */
    public static function files(Request $request): array
    {
        $files = array_filter((array) $request->file('uploads', []));
        foreach ($files as $type => $file) {
            app(\Modules\Academic\Services\DocumentService::class)->validate((string) $type, $file, "uploads.{$type}");
        }

        return $files;
    }

    /** Drops empty second-guardian rows. */
    private static function clean(Request $request): array
    {
        $g = collect($request->input('guardians', []))->filter(fn ($x) => trim((string) ($x['name'] ?? '')) !== '' || trim((string) ($x['phone'] ?? '')) !== '')->values()->all();

        return ['guardians' => $g] + $request->all();
    }

    private function options(): array
    {
        return ['sessions' => AcademicSession::where('status', '!=', 'closed')->orderByDesc('starts_on')->get(), 'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'levels' => GradeLevel::with('program')->where('is_active', true)->get()->sortBy(fn ($l) => [$l->program->sort_order, $l->sequence])];
    }
}
