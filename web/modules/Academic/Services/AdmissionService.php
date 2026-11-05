<?php

namespace Modules\Academic\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Models\AdmissionApplication;
use Modules\Academic\Models\Section;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\Institution;
use Modules\Academic\Support\Numbering;
use Modules\Academic\Support\Rules;
use Modules\Institution\Models\AcademicSession;

/** Enquiries and applications through to admission. */
final class AdmissionService
{
    public function __construct(private readonly StudentService $students)
    {
    }

    public static function rules(): array
    {
        return [
            'session_id' => ['required', Rules::exists('academic_sessions')],
            'branch_id' => ['required', Rules::exists('branches', ['is_active' => true])],
            'grade_level_id' => ['required', Rules::exists('grade_levels', ['is_active' => true])],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'previous_school' => ['nullable', 'string', 'max:200'],
            'guardians' => ['required', 'array', 'min:1', 'max:4'],
            'guardians.*.name' => ['required', 'string', 'max:150'],
            'guardians.*.relation' => ['required', Rule::in(array_keys(config('academic.relations')))],
            'guardians.*.phone' => ['required', Rules::PHONE],
            'guardians.*.email' => ['nullable', 'email'],
            'guardians.*.occupation' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:500'],
            'source' => ['required', Rule::in(array_keys(config('academic.admission.sources')))],
            'follow_up_on' => ['nullable', 'date'],
            'test_on' => ['nullable', 'date'],
            'test_score' => ['nullable', 'numeric', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function create(array $data, string $stage = 'enquiry'): AdmissionApplication
    {
        if (! in_array($stage, ['enquiry', 'applied'], true)) {
            throw new AcademicRuleException('New records start as an enquiry or an application.');
        }
        $d = Rules::check($data, self::rules(), ['guardians.required' => 'Add at least one parent or guardian.']);
        $session = AcademicSession::findOrFail($d['session_id']);
        if ($session->status === 'closed') {
            throw new AcademicRuleException("Session {$session->name} is closed for admissions.", 'session_id');
        }

        return DB::transaction(function () use ($d, $session, $stage) {
            $app = AdmissionApplication::create($d + [
                'number' => sprintf('APP/%s/%05d', $session->name, Numbering::next('APP', $session->name)),
                'phone' => $d['guardians'][0]['phone'],
                'stage' => $stage,
                'stage_changed_at' => now(),
                'documents' => [],
            ]);
            Audit::record('admission.created', $app, ['number' => $app->number, 'stage' => $stage]);

            return $app;
        });
    }

    public function update(AdmissionApplication $app, array $data): AdmissionApplication
    {
        if (! $app->isOpen()) {
            throw new AcademicRuleException("Application {$app->number} is {$app->stage} and cannot be edited.");
        }
        $d = Rules::check($data, self::rules());
        $app->update($d + ['phone' => $d['guardians'][0]['phone']]);

        return $app;
    }

    public function move(AdmissionApplication $app, string $stage, ?string $reason = null): AdmissionApplication
    {
        $allowed = config("academic.admission.transitions.{$app->stage}", []);
        if ($stage === 'admitted') {
            throw new AcademicRuleException('Use “Admit” to choose a section and create the student record.');
        }
        if (! in_array($stage, $allowed, true)) {
            throw new AcademicRuleException('An application that is '.str_replace('_', ' ', $app->stage).' cannot move to '.str_replace('_', ' ', $stage).'.', 'stage');
        }
        if (in_array($stage, ['rejected', 'withdrawn'], true) && trim((string) $reason) === '') {
            throw new AcademicRuleException('Give a reason.', 'reason');
        }
        $from = $app->stage;
        $app->update(['stage' => $stage, 'stage_changed_at' => now(), 'rejection_reason' => in_array($stage, ['rejected', 'withdrawn'], true) ? $reason : $app->rejection_reason]);
        Audit::record('admission.stage_changed', $app, ['from' => $from, 'to' => $stage, 'reason' => $reason]);

        return $app;
    }

    /** @param array<string, bool> $documents */
    public function setDocuments(AdmissionApplication $app, array $documents): AdmissionApplication
    {
        $known = array_keys(config('academic.admission.documents'));
        $uploaded = \Modules\Academic\Models\StudentDocument::where('application_id', $app->id)->pluck('type')->all();   // an uploaded file always counts as received
        $app->update(['documents' => collect($known)->mapWithKeys(fn ($k) => [$k => (bool) ($documents[$k] ?? false) || in_array($k, $uploaded, true)])->all()]);

        return $app;
    }

    /** Creates the student, links guardians and enrols them. The application must have an offer. */
    public function admit(AdmissionApplication $app, array $data)
    {
        $d = Rules::check($data, [
            'section_id' => ['required', Rules::exists('sections', ['is_active' => true])],
            'roll_no' => ['nullable', 'integer', 'between:1,9999'],
            'admitted_on' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => [$app->gender ? 'nullable' : 'required', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => [$app->date_of_birth ? 'nullable' : 'required', 'date', 'before:today'],
        ]);
        if ($app->stage !== 'offered') {
            throw new AcademicRuleException('Only applications with an offer can be admitted. This one is '.str_replace('_', ' ', $app->stage).'.');
        }
        $missing = $app->missingDocuments(Institution::profile()->required_documents ?? []);
        if ($missing) {
            $labels = array_map(fn ($k) => config("academic.admission.documents.{$k}"), $missing);
            throw new AcademicRuleException('Documents still needed: '.implode(', ', $labels).'.', 'documents');
        }
        $section = Section::findOrFail($d['section_id']);
        if ($section->session_id !== $app->session_id || $section->grade_level_id !== $app->grade_level_id || $section->branch_id !== $app->branch_id) {
            throw new AcademicRuleException('Choose a '.strtolower(Institution::term('section')).' of '.$app->level->name.' in the same session and branch as the application.', 'section_id');
        }

        return DB::transaction(function () use ($app, $d, $section) {
            $student = $this->students->create([
                'first_name' => $app->first_name, 'last_name' => $app->last_name,
                'gender' => $d['gender'] ?? $app->gender, 'date_of_birth' => $d['date_of_birth'] ?? $app->date_of_birth?->format('Y-m-d'),
                'email' => $app->email, 'address' => $app->address, 'previous_school' => $app->previous_school,
                'section_id' => $section->id, 'roll_no' => $d['roll_no'] ?? null, 'admitted_on' => $d['admitted_on'] ?? today()->format('Y-m-d'),
                'guardians' => array_values($app->guardians),
            ]);
            $app->update(['stage' => 'admitted', 'stage_changed_at' => now(), 'student_id' => $student->id]);
            app(DocumentService::class)->attachToStudent($app, $student);
            Audit::record('admission.admitted', $app, ['student' => $student->admission_no]);

            return $student;
        });
    }

    /** Counts by stage and source for a session, with conversion. */
    public function funnel(string $sessionId): array
    {
        $rows = AdmissionApplication::where('session_id', $sessionId)->get(['stage', 'source']);
        $byStage = collect(config('academic.admission.stages'))->mapWithKeys(fn ($s) => [$s => $rows->where('stage', $s)->count()])->all();
        $bySource = collect(config('academic.admission.sources'))->mapWithKeys(fn ($l, $k) => [$k => [
            'label' => $l, 'total' => $rows->where('source', $k)->count(), 'admitted' => $rows->where('source', $k)->where('stage', 'admitted')->count(),
        ]])->all();

        return ['total' => $rows->count(), 'stages' => $byStage, 'sources' => $bySource,
            'conversion' => $rows->count() ? round($byStage['admitted'] * 100 / $rows->count(), 1) : 0.0];
    }
}
