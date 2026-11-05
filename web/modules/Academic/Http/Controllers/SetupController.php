<?php

namespace Modules\Academic\Http\Controllers;

use App\Core\Support\IndianStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Models\AcademicTerm;
use Modules\Academic\Models\BellPeriod;
use Modules\Academic\Models\CertificateTemplate;
use Modules\Academic\Models\Exam;
use Modules\Academic\Models\GradeLevel;
use Modules\Academic\Models\GradingScheme;
use Modules\Academic\Models\Holiday;
use Modules\Academic\Models\Program;
use Modules\Academic\Models\Subject;
use Modules\Academic\Models\TimetableEntry;
use Modules\Academic\Services\CertificateService;
use Modules\Academic\Services\SetupService;
use Modules\Academic\Support\Institution;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;

/** Institution profile, branches, sessions and terms, structure, grading, bell schedule, holidays, certificate templates. */
class SetupController extends Controller
{
    public function __construct(private readonly SetupService $setup)
    {
    }

    public function index(): View
    {
        return view('academic::setup.index', ['profile' => Institution::profile(), 'tenant' => Institution::tenant(),
            'branches' => Branch::orderByDesc('is_primary')->orderBy('name')->get(), 'states' => IndianStates::ALL,
            'branchLimit' => Institution::features()->limit('branches_max')]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $this->setup->updateProfile($request->all() + ['working_days' => [], 'required_documents' => []]);

        return $this->done('Institution profile saved.');
    }

    public function storeBranch(Request $request): RedirectResponse
    {
        $this->setup->saveBranch($request->all());

        return $this->done('Branch added.');
    }

    public function updateBranch(Request $request, Branch $branch): RedirectResponse
    {
        $this->setup->saveBranch($request->all(), $branch);

        return $this->done('Branch saved.');
    }

    /* Sessions and terms */

    public function sessions(): View
    {
        return view('academic::setup.sessions', ['sessions' => AcademicSession::with('terms')->orderByDesc('starts_on')->get()]);
    }

    public function storeSession(Request $request): RedirectResponse
    {
        $s = $this->setup->saveSession($request->all());

        return $this->done("Session {$s->name} added. Make it current when the new year starts.");
    }

    public function updateSession(Request $request, AcademicSession $session): RedirectResponse
    {
        $this->setup->saveSession($request->all(), $session);

        return $this->done('Session saved.');
    }

    public function makeCurrent(AcademicSession $session): RedirectResponse
    {
        $this->setup->makeCurrent($session);

        return $this->done("{$session->name} is now the current session.");
    }

    public function closeSession(AcademicSession $session): RedirectResponse
    {
        $this->setup->closeSession($session);

        return $this->done("Session {$session->name} closed.");
    }

    public function storeTerm(Request $request, AcademicSession $session): RedirectResponse
    {
        $this->setup->saveTerm($session, $request->all());

        return $this->done(Institution::term('term').' added.');
    }

    public function updateTerm(Request $request, AcademicTerm $term): RedirectResponse
    {
        $this->setup->saveTerm($term->session, $request->all(), $term);

        return $this->done(Institution::term('term').' saved.');
    }

    public function destroyTerm(AcademicTerm $term): RedirectResponse
    {
        if (Exam::where('term_id', $term->id)->exists()) {
            throw new AcademicRuleException('Exams belong to this '.strtolower(Institution::term('term')).', so it cannot be deleted.');
        }
        $term->delete();

        return $this->done(Institution::term('term').' deleted.');
    }

    /* Academic structure */

    public function structure(): View
    {
        return view('academic::setup.structure', [
            'programs' => Program::with(['levels.subjects', 'gradingScheme'])->orderBy('sort_order')->orderBy('name')->get(),
            'subjects' => Subject::orderBy('name')->get(), 'schemes' => GradingScheme::orderBy('name')->get(),
        ]);
    }

    public function storeProgram(Request $request): RedirectResponse
    {
        $this->setup->saveProgram($request->all());

        return $this->done(Institution::term('program').' added.');
    }

    public function updateProgram(Request $request, Program $program): RedirectResponse
    {
        $this->setup->saveProgram($request->all(), $program);

        return $this->done(Institution::term('program').' saved.');
    }

    public function storeLevel(Request $request, Program $program): RedirectResponse
    {
        $this->setup->saveLevel($program, $request->all());

        return $this->done(Institution::term('level').' added.');
    }

    public function updateLevel(Request $request, GradeLevel $level): RedirectResponse
    {
        $this->setup->saveLevel($level->program, $request->all(), $level);

        return $this->done(Institution::term('level').' saved.');
    }

    public function levelSubjects(Request $request, GradeLevel $level): RedirectResponse
    {
        $this->setup->setLevelSubjects($level, (array) $request->input('subjects', []), (array) $request->input('optional', []));

        return $this->done("Subjects for {$level->name} saved. New sections copy them.");
    }

    public function storeSubject(Request $request): RedirectResponse
    {
        $this->setup->saveSubject($request->all());

        return $this->done(Institution::term('subject').' added.');
    }

    public function updateSubject(Request $request, Subject $subject): RedirectResponse
    {
        $this->setup->saveSubject($request->all(), $subject);

        return $this->done(Institution::term('subject').' saved.');
    }

    /* Grading */

    public function grading(): View
    {
        return view('academic::setup.grading', ['schemes' => GradingScheme::with('bands')->orderBy('name')->get()]);
    }

    public function storeScheme(Request $request): RedirectResponse
    {
        $this->setup->saveGradingScheme(self::withBands($request));

        return $this->done('Grading scheme added.');
    }

    public function updateScheme(Request $request, GradingScheme $scheme): RedirectResponse
    {
        $this->setup->saveGradingScheme(self::withBands($request), $scheme);

        return $this->done('Grading scheme saved.');
    }

    /** Drops empty band rows from the form. */
    private static function withBands(Request $request): array
    {
        $bands = collect($request->input('bands', []))->filter(fn ($b) => trim((string) ($b['grade'] ?? '')) !== '' || trim((string) ($b['min_percent'] ?? '')) !== '')->values()->all();

        return ['bands' => $bands] + $request->all();
    }

    /* Bell schedule and holidays */

    public function calendar(): View
    {
        return view('academic::setup.calendar', [
            'branches' => Branch::with([])->orderByDesc('is_primary')->get(),
            'periods' => BellPeriod::orderBy('sequence')->get()->groupBy('branch_id'),
            'holidays' => Holiday::orderBy('starts_on')->get(),
        ]);
    }

    public function storePeriod(Request $request): RedirectResponse
    {
        $this->setup->savePeriod($request->all());

        return $this->done('Period added.');
    }

    public function updatePeriod(Request $request, BellPeriod $period): RedirectResponse
    {
        $this->setup->savePeriod($request->all() + ['branch_id' => $period->branch_id], $period);

        return $this->done('Period saved.');
    }

    public function destroyPeriod(BellPeriod $period): RedirectResponse
    {
        if (TimetableEntry::where('period_id', $period->id)->exists()) {
            throw new AcademicRuleException('The timetable uses this period. Clear those slots first.');
        }
        $period->delete();

        return $this->done('Period deleted.');
    }

    public function storeHoliday(Request $request): RedirectResponse
    {
        $this->setup->saveHoliday($request->all());

        return $this->done('Holiday added.');
    }

    public function destroyHoliday(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();

        return $this->done('Holiday removed.');
    }

    /* Certificate templates */

    public function templates(): View
    {
        return view('academic::setup.templates', ['templates' => CertificateTemplate::orderBy('type')->orderBy('name')->get(),
            'placeholders' => CertificateService::PLACEHOLDERS]);
    }

    public function storeTemplate(Request $request, CertificateService $svc): RedirectResponse
    {
        $svc->saveTemplate($request->all());

        return $this->done('Template added.');
    }

    public function updateTemplate(Request $request, CertificateTemplate $template, CertificateService $svc): RedirectResponse
    {
        $svc->saveTemplate($request->all(), $template);

        return $this->done('Template saved.');
    }
}
