<?php

namespace Modules\Academic\Services;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Mail\AccountInvitation;
use Modules\Academic\Models\AcademicTerm;
use Modules\Academic\Models\BellPeriod;
use Modules\Academic\Models\GradeLevel;
use Modules\Academic\Models\GradingScheme;
use Modules\Academic\Models\Holiday;
use Modules\Academic\Models\InstitutionProfile;
use Modules\Academic\Models\LevelSubject;
use Modules\Academic\Models\Program;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\SectionSubject;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Models\Subject;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\Institution;
use Modules\Academic\Support\Rules;
use Modules\Identity\Models\TenantMembership;
use Modules\Identity\Models\User;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;

/** Institution and branch setup, sessions and terms, the academic structure, staff, bell schedule and holidays. */
final class SetupService
{
    /* ---------- Institution profile ---------- */

    public function updateProfile(array $data): InstitutionProfile
    {
        $d = Rules::check($data, [
            'structure' => ['required', Rule::in(array_keys(config('academic.structures')))],
            'board' => ['nullable', 'string', 'max:40'],
            'affiliation_no' => ['nullable', 'string', 'max:40'],
            'udise_code' => ['nullable', 'digits:11'],
            'principal_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', Rules::PHONE],
            'email' => ['nullable', 'email', 'max:191'],
            'website' => ['nullable', 'url', 'max:191'],
            'address' => ['nullable', 'string', 'max:500'],
            'attendance_mode' => ['required', Rule::in(['daily', 'period'])],
            'attendance_edit_days' => ['required', 'integer', 'between:0,30'],
            'low_attendance_percent' => ['required', 'integer', 'between:1,100'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['integer', 'between:1,7'],
            'admission_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'required_documents' => ['nullable', 'array'],
            'required_documents.*' => [Rule::in(array_keys(config('academic.admission.documents')))],
        ], ['udise_code.digits' => 'A UDISE code has 11 digits.', 'admission_prefix.regex' => 'Use capital letters and numbers only.']);

        $profile = Institution::profile();
        $d['working_days'] = array_values(array_unique(array_map('intval', $d['working_days'])));
        sort($d['working_days']);
        $d['required_documents'] = array_values($d['required_documents'] ?? []);
        $profile->update($d);
        Audit::record('setup.profile_updated', $profile, ['fields' => array_keys($d)]);
        Institution::forget();

        return $profile;
    }

    /* ---------- Branches ---------- */

    public function saveBranch(array $data, ?Branch $branch = null): Branch
    {
        $data['code'] = strtoupper(trim((string) ($data['code'] ?? '')));
        $d = Rules::check($data, [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rules::unique('branches', 'code', $branch?->id)],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_code' => ['nullable', Rule::in(array_keys(\App\Core\Support\IndianStates::ALL))],
            'phone' => ['nullable', Rules::PHONE],
            'email' => ['nullable', 'email'],
            'is_active' => ['boolean'],
        ]);

        if (! $branch && ! Institution::features()->allowsAnother('branches_max', Branch::count())) {
            throw new AcademicRuleException('Your plan allows '.Institution::features()->limit('branches_max').' branch(es). Ask Quick School AI to upgrade the plan.');
        }
        if ($branch && $branch->is_primary && array_key_exists('is_active', $d) && ! $d['is_active']) {
            throw new AcademicRuleException('The main branch cannot be deactivated.');
        }

        $branch ??= new Branch();
        $branch->fill($d)->save();
        Audit::record($branch->wasRecentlyCreated ? 'setup.branch_created' : 'setup.branch_updated', $branch, $d);

        return $branch;
    }

    /* ---------- Sessions and terms ---------- */

    public function saveSession(array $data, ?AcademicSession $session = null): AcademicSession
    {
        $d = Rules::check($data, [
            'name' => ['required', 'string', 'max:20', Rules::unique('academic_sessions', 'name', $session?->id)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'status' => ['nullable', Rule::in(AcademicSession::STATUSES)],
        ], ['ends_on.after' => 'The session must end after it starts.']);

        $overlap = AcademicSession::where('starts_on', '<=', $d['ends_on'])->where('ends_on', '>=', $d['starts_on'])
            ->when($session, fn ($q) => $q->whereKeyNot($session->id))->first();
        if ($overlap) {
            throw new AcademicRuleException("These dates overlap session {$overlap->name}.", 'starts_on');
        }
        if ($session && AcademicTerm::where('session_id', $session->id)
            ->where(fn ($q) => $q->where('starts_on', '<', $d['starts_on'])->orWhere('ends_on', '>', $d['ends_on']))->exists()) {
            throw new AcademicRuleException('A term falls outside the new dates. Change the terms first.', 'starts_on');
        }

        $session ??= new AcademicSession(['is_current' => false]);
        $session->fill($d + ['status' => $session->status ?? 'planning'])->save();
        Audit::record('setup.session_saved', $session, $d);

        return $session;
    }

    public function makeCurrent(AcademicSession $session): void
    {
        if ($session->status === 'closed') {
            throw new AcademicRuleException('A closed session cannot be made current.');
        }
        DB::transaction(function () use ($session) {
            AcademicSession::where('is_current', true)->update(['is_current' => false]);
            $session->update(['is_current' => true, 'status' => 'open']);
        });
        Audit::record('setup.session_made_current', $session);
    }

    public function closeSession(AcademicSession $session): void
    {
        if ($session->is_current) {
            throw new AcademicRuleException('Make another session current before closing this one.');
        }
        $session->update(['status' => 'closed']);
        Audit::record('setup.session_closed', $session);
    }

    public function saveTerm(AcademicSession $session, array $data, ?AcademicTerm $term = null): AcademicTerm
    {
        $d = Rules::check($data, [
            'name' => ['required', 'string', 'max:40', Rules::unique('academic_terms', 'name', $term?->id, ['session_id' => $session->id])],
            'sequence' => ['required', 'integer', 'between:1,12'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);
        if (! $session->contains($d['starts_on']) || ! $session->contains($d['ends_on'])) {
            throw new AcademicRuleException("The term must fall within session {$session->name} ({$session->starts_on->format('j M Y')} to {$session->ends_on->format('j M Y')}).", 'starts_on');
        }
        $clash = AcademicTerm::where('session_id', $session->id)->where('starts_on', '<=', $d['ends_on'])->where('ends_on', '>=', $d['starts_on'])
            ->when($term, fn ($q) => $q->whereKeyNot($term->id))->first();
        if ($clash) {
            throw new AcademicRuleException("These dates overlap {$clash->name}.", 'starts_on');
        }

        $term ??= new AcademicTerm(['session_id' => $session->id]);
        $term->fill($d)->save();

        return $term;
    }

    /* ---------- Programmes, levels, subjects ---------- */

    public function saveProgram(array $data, ?Program $program = null): Program
    {
        $data['code'] = strtoupper(trim((string) ($data['code'] ?? '')));
        $d = Rules::check($data, [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rules::unique('programs', 'code', $program?->id)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'grading_scheme_id' => ['nullable', Rules::exists('grading_schemes')],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        $program ??= new Program();
        $program->fill($d)->save();
        Audit::record('setup.program_saved', $program, $d);

        return $program;
    }

    public function saveLevel(Program $program, array $data, ?GradeLevel $level = null): GradeLevel
    {
        $data['code'] = strtoupper(trim((string) ($data['code'] ?? '')));
        $d = Rules::check($data, [
            'code' => ['required', 'string', 'max:20', Rules::unique('grade_levels', 'code', $level?->id, ['program_id' => $program->id])],
            'name' => ['required', 'string', 'max:80'],
            'sequence' => ['required', 'integer', 'between:1,99', Rules::unique('grade_levels', 'sequence', $level?->id, ['program_id' => $program->id])],
            'is_active' => ['boolean'],
        ], ['sequence.unique' => 'Another level already has this position.']);
        $level ??= new GradeLevel(['program_id' => $program->id]);
        $level->fill($d)->save();

        return $level;
    }

    public function saveSubject(array $data, ?Subject $subject = null): Subject
    {
        $data['code'] = strtoupper(trim((string) ($data['code'] ?? '')));
        $d = Rules::check($data, [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rules::unique('subjects', 'code', $subject?->id)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(config('academic.subject_types')))],
            'credits' => ['nullable', 'numeric', 'between:0,40'],
            'is_active' => ['boolean'],
        ]);
        $subject ??= new Subject();
        $subject->fill($d)->save();

        return $subject;
    }

    /** Subjects taught at a level; copied into new sections of that level. */
    public function setLevelSubjects(GradeLevel $level, array $subjectIds, array $optionalIds = []): void
    {
        Rules::check(['subjects' => $subjectIds], ['subjects' => ['array'], 'subjects.*' => [Rules::exists('subjects')]]);
        DB::transaction(function () use ($level, $subjectIds, $optionalIds) {
            LevelSubject::where('grade_level_id', $level->id)->delete();
            foreach (array_unique($subjectIds) as $id) {
                LevelSubject::create(['grade_level_id' => $level->id, 'subject_id' => $id, 'is_optional' => in_array($id, $optionalIds, true)]);
            }
        });
    }

    /* ---------- Sections and batches ---------- */

    public function saveSection(array $data, ?Section $section = null): Section
    {
        $d = Rules::check($data, [
            'session_id' => ['required', Rules::exists('academic_sessions')],
            'branch_id' => ['required', Rules::exists('branches')],
            'grade_level_id' => ['required', Rules::exists('grade_levels')],
            'name' => ['required', 'string', 'max:50'],
            'capacity' => ['nullable', 'integer', 'between:1,1000'],
            'class_teacher_id' => ['nullable', Rules::exists('staff_members', ['status' => 'active'])],
            'room' => ['nullable', 'string', 'max:40'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'schedule_note' => ['nullable', 'string', 'max:150'],
            'is_active' => ['boolean'],
        ]);

        $session = AcademicSession::findOrFail($d['session_id']);
        if ($session->status === 'closed') {
            throw new AcademicRuleException("Session {$session->name} is closed.", 'session_id');
        }
        $dupe = Section::where(['session_id' => $d['session_id'], 'branch_id' => $d['branch_id'], 'grade_level_id' => $d['grade_level_id'], 'name' => $d['name']])
            ->when($section, fn ($q) => $q->whereKeyNot($section->id))->exists();
        if ($dupe) {
            throw new AcademicRuleException('A '.strtolower(Institution::term('section')).' with this name already exists for this '.strtolower(Institution::term('level')).'.', 'name');
        }
        if ($section && isset($d['capacity']) && $d['capacity'] < $section->activeCount()) {
            throw new AcademicRuleException("Capacity cannot be less than the {$section->activeCount()} students already enrolled.", 'capacity');
        }
        if ($section && $section->activeCount() > 0 && ($d['grade_level_id'] !== $section->grade_level_id || $d['session_id'] !== $section->session_id)) {
            throw new AcademicRuleException('Students are enrolled, so the '.strtolower(Institution::term('level')).' and session cannot change.', 'grade_level_id');
        }

        return DB::transaction(function () use ($d, $section) {
            $isNew = ! $section;
            $section ??= new Section();
            $section->fill($d)->save();
            if ($isNew) {
                foreach (LevelSubject::where('grade_level_id', $section->grade_level_id)->get() as $ls) {
                    SectionSubject::create(['section_id' => $section->id, 'subject_id' => $ls->subject_id]);
                }
            }
            Audit::record($isNew ? 'setup.section_created' : 'setup.section_updated', $section, $d);

            return $section;
        });
    }

    public function assignSubject(Section $section, array $data): SectionSubject
    {
        $d = Rules::check($data, [
            'subject_id' => ['required', Rules::exists('subjects', ['is_active' => true])],
            'teacher_id' => ['nullable', Rules::exists('staff_members', ['status' => 'active'])],
            'periods_per_week' => ['nullable', 'integer', 'between:0,60'],
        ]);
        $row = SectionSubject::firstOrNew(['section_id' => $section->id, 'subject_id' => $d['subject_id']]);
        $row->fill($d)->save();

        return $row;
    }

    public function removeSubject(SectionSubject $row): void
    {
        $used = \Modules\Academic\Models\ExamPaper::where('section_id', $row->section_id)->where('subject_id', $row->subject_id)->exists();
        if ($used) {
            throw new AcademicRuleException('This subject has exam papers in this section, so it cannot be removed.');
        }
        \Modules\Academic\Models\TimetableEntry::where('section_id', $row->section_id)->where('subject_id', $row->subject_id)->delete();
        $row->delete();
    }

    /* ---------- Staff ---------- */

    public function saveStaff(array $data, ?StaffMember $staff = null): StaffMember
    {
        $data['employee_code'] = strtoupper(trim((string) ($data['employee_code'] ?? '')));
        if (! empty($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }
        $d = Rules::check($data, [
            'employee_code' => ['required', 'string', 'max:20', Rules::unique('staff_members', 'employee_code', $staff?->id)],
            'name' => ['required', 'string', 'max:150'],
            'designation' => ['nullable', 'string', 'max:80'],
            'department' => ['nullable', 'string', 'max:80'],
            'branch_id' => ['nullable', Rules::exists('branches')],
            'email' => ['nullable', 'email', 'max:191', 'required_if:create_login,1,true'],
            'phone' => ['nullable', Rules::PHONE],
            'is_teaching' => ['boolean'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'joined_on' => ['nullable', 'date'],
            'create_login' => ['nullable', 'boolean'],
            'role' => ['nullable', Rule::in(['admin', 'front_office', 'teacher', 'accountant'])],
        ], ['email.required_if' => 'An email is needed to create a login.']);

        if (! $staff && ! Institution::features()->allowsAnother('staff_max', StaffMember::where('status', 'active')->count())) {
            throw new AcademicRuleException('Your plan allows '.Institution::features()->limit('staff_max').' staff accounts. Ask Quick School AI to upgrade the plan.');
        }
        if ($staff && ($d['status'] ?? 'active') === 'inactive' && Section::where('class_teacher_id', $staff->id)->whereHas('session', fn ($q) => $q->where('is_current', true))->exists()) {
            throw new AcademicRuleException('This person is a class teacher this session. Assign another class teacher first.', 'status');
        }

        $login = (bool) ($d['create_login'] ?? false);
        $role = $d['role'] ?? (($d['is_teaching'] ?? true) ? 'teacher' : 'front_office');
        unset($d['create_login'], $d['role']);

        $invite = null;
        $staff = DB::transaction(function () use ($d, $staff, $login, $role, &$invite) {
            $staff ??= new StaffMember();
            $staff->fill($d)->save();

            if ($login && ! $staff->user_id) {
                $user = User::firstOrCreate(['email' => $d['email']], ['name' => $d['name'], 'password' => Str::random(48)]);
                if (StaffMember::where('user_id', $user->id)->whereKeyNot($staff->id)->exists()) {
                    throw new AcademicRuleException('This email already belongs to another staff member.', 'email');
                }
                $staff->update(['user_id' => $user->id]);
                $m = TenantMembership::firstOrNew(['user_id' => $user->id]);
                if ($m->exists && $m->role === 'owner') {
                    $role = 'owner';
                }
                $m->fill(['role' => $role, 'status' => 'active', 'default_branch_id' => $d['branch_id'] ?? null])->save();
                $invite = $user;
            }
            if ($staff->user_id && ($d['status'] ?? 'active') === 'inactive') {
                TenantMembership::where('user_id', $staff->user_id)->where('role', '!=', 'owner')->update(['status' => 'inactive']);
            }
            Audit::record($staff->wasRecentlyCreated ? 'staff.created' : 'staff.updated', $staff, ['role' => $login ? $role : null]);

            return $staff;
        });

        if ($invite) {
            self::invite($invite, $role);
        }

        return $staff;
    }

    /** Emails a set-password link for this institution. */
    public static function invite(User $user, string $role): void
    {
        $tenant = Institution::tenant();
        $token = Password::broker('users')->createToken($user);
        $link = 'https://'.$tenant->code.'.'.config('platform.base_domain').'/set-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
        Mail::to($user->email)->send(new AccountInvitation($tenant->name, $user->name, config("academic.roles.{$role}", $role), $link));
    }

    /* ---------- Grading schemes ---------- */

    /** @param array{name: string, type: string, pass_percent: numeric, bands: list<array{grade: string, min_percent: numeric, grade_point?: ?numeric, remark?: ?string}>} $data */
    public function saveGradingScheme(array $data, ?GradingScheme $scheme = null): GradingScheme
    {
        $d = Rules::check($data, [
            'name' => ['required', 'string', 'max:80', Rules::unique('grading_schemes', 'name', $scheme?->id)],
            'type' => ['required', Rule::in(['grades', 'gpa', 'percentage'])],
            'pass_percent' => ['required', 'numeric', 'between:0,100'],
            'bands' => ['required', 'array', 'min:2'],
            'bands.*.grade' => ['required', 'string', 'max:20', 'distinct'],
            'bands.*.min_percent' => ['required', 'numeric', 'between:0,100', 'distinct'],
            'bands.*.grade_point' => ['nullable', 'numeric', 'between:0,10', 'required_if:type,gpa'],
            'bands.*.remark' => ['nullable', 'string', 'max:80'],
        ], ['bands.*.grade.distinct' => 'Each grade must be different.', 'bands.*.min_percent.distinct' => 'Two grades have the same minimum.',
            'bands.*.grade_point.required_if' => 'Give every grade a grade point for a GPA scheme.']);

        if (collect($d['bands'])->min(fn ($b) => (float) $b['min_percent']) != 0) {
            throw new AcademicRuleException('One grade must start at 0% so every mark gets a grade.', 'bands');
        }

        return DB::transaction(function () use ($d, $scheme) {
            $scheme ??= new GradingScheme();
            $scheme->fill(collect($d)->except('bands')->all())->save();
            $scheme->bands()->delete();
            foreach ($d['bands'] as $b) {
                $scheme->bands()->create(['grade' => $b['grade'], 'min_percent' => $b['min_percent'], 'grade_point' => $b['grade_point'] ?? null, 'remark' => $b['remark'] ?? null]);
            }

            return $scheme->load('bands');
        });
    }

    /* ---------- Bell schedule and holidays ---------- */

    public function savePeriod(array $data, ?BellPeriod $period = null): BellPeriod
    {
        $d = Rules::check($data, [
            'branch_id' => ['required', Rules::exists('branches')],
            'name' => ['required', 'string', 'max:40'],
            'sequence' => ['required', 'integer', 'between:1,30', Rules::unique('bell_periods', 'sequence', $period?->id, ['branch_id' => $data['branch_id'] ?? null])],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'is_break' => ['boolean'],
        ], ['sequence.unique' => 'Another period already has this position.', 'ends_at.after' => 'The period must end after it starts.']);
        $clash = BellPeriod::where('branch_id', $d['branch_id'])->where('starts_at', '<', $d['ends_at'].':00')->where('ends_at', '>', $d['starts_at'].':00')
            ->when($period, fn ($q) => $q->whereKeyNot($period->id))->first();
        if ($clash) {
            throw new AcademicRuleException("This overlaps {$clash->name} ({$clash->timeRange()}).", 'starts_at');
        }
        $period ??= new BellPeriod();
        $period->fill($d)->save();

        return $period;
    }

    public function saveHoliday(array $data, ?Holiday $holiday = null): Holiday
    {
        $d = Rules::check($data, [
            'name' => ['required', 'string', 'max:120'],
            'branch_id' => ['nullable', Rules::exists('branches')],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);
        $holiday ??= new Holiday();
        $holiday->fill($d)->save();

        return $holiday;
    }
}
