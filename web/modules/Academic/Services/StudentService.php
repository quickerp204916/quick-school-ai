<?php

namespace Modules\Academic\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Models\Enrollment;
use Modules\Academic\Models\Guardian;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\Student;
use Modules\Academic\Models\StudentGuardian;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\Institution;
use Modules\Academic\Support\Numbering;
use Modules\Academic\Support\Rules;

/** Student records, guardians, enrolment in sections and roll numbers. */
final class StudentService
{
    public static function profileRules(?Student $student = null): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1950-01-01'],
            'blood_group' => ['nullable', Rule::in(config('academic.blood_groups'))],
            'category' => ['nullable', Rule::in(array_keys(config('academic.categories')))],
            'nationality' => ['nullable', 'string', 'max:40'],
            'mother_tongue' => ['nullable', 'string', 'max:40'],
            'pen' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z]+$/'],
            'apaar_id' => ['nullable', 'digits:12'],
            'aadhaar_last4' => ['nullable', 'digits:4'],
            'phone' => ['nullable', Rules::PHONE],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_code' => ['nullable', Rule::in(array_keys(\App\Core\Support\IndianStates::ALL))],
            'pincode' => ['nullable', 'regex:/^[1-9]\d{5}$/'],
            'previous_school' => ['nullable', 'string', 'max:200'],
            'conduct' => ['nullable', 'string', 'max:40'],
        ];
    }

    public static function guardianRules(string $prefix = ''): array
    {
        return [
            $prefix.'name' => ['required', 'string', 'max:150'],
            $prefix.'relation' => ['required', Rule::in(array_keys(config('academic.relations')))],
            $prefix.'phone' => ['required', Rules::PHONE],
            $prefix.'alt_phone' => ['nullable', Rules::PHONE],
            $prefix.'email' => ['nullable', 'email', 'max:191'],
            $prefix.'occupation' => ['nullable', 'string', 'max:100'],
            $prefix.'address' => ['nullable', 'string', 'max:500'],
            $prefix.'is_primary' => ['nullable', 'boolean'],
            $prefix.'can_pickup' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Admit a student directly into a section (without an application).
     * Data: profile fields, section_id, optional roll_no, admitted_on, admission_no (for migrating old records),
     * guardians: list of guardian fields (at least one).
     */
    public function create(array $data): Student
    {
        $d = Rules::check($data, self::profileRules() + [
            'section_id' => ['required', Rules::exists('sections', ['is_active' => true])],
            'roll_no' => ['nullable', 'integer', 'between:1,9999'],
            'admitted_on' => ['nullable', 'date', 'before_or_equal:today'],
            'admission_no' => ['nullable', 'string', 'max:30', Rules::unique('students', 'admission_no')],
            'guardians' => ['required', 'array', 'min:1', 'max:4'],
        ] + self::guardianRules('guardians.*.'), ['guardians.required' => 'Add at least one parent or guardian.']);

        $section = Section::with('session')->findOrFail($d['section_id']);
        $active = Student::where('status', 'active')->count();
        if (! Institution::features()->allowsAnother('students_max', $active)) {
            throw new AcademicRuleException('Your plan allows '.Institution::features()->limit('students_max').' active students. Ask Quick School AI to upgrade the plan.');
        }

        return DB::transaction(function () use ($d, $section) {
            $admittedOn = Carbon::parse($d['admitted_on'] ?? today());
            $student = Student::create(collect($d)->only(array_keys(self::profileRules()))->all() + [
                'admission_no' => $d['admission_no'] ?? $this->nextAdmissionNo($section),
                'branch_id' => $section->branch_id,
                'admitted_on' => $admittedOn,
                'admitted_session_id' => $section->session_id,
                'nationality' => $d['nationality'] ?? 'Indian',
                'status' => 'active',
            ]);

            $hasPrimary = collect($d['guardians'])->contains(fn ($g) => ! empty($g['is_primary']));
            foreach (array_values($d['guardians']) as $i => $g) {
                $this->linkGuardian($student, $g + ['is_primary' => ! $hasPrimary && $i === 0]);
            }

            $this->enroll($student, $section, $d['roll_no'] ?? null, $admittedOn->max($section->session->starts_on));
            Audit::record('student.admitted', $student, ['admission_no' => $student->admission_no, 'section' => $section->id]);

            return $student->load(['guardians', 'currentEnrollment.section.level']);
        });
    }

    /** SPS/2026/0001: prefix, session start year, running number. */
    public function nextAdmissionNo(Section $section): string
    {
        $year = $section->session->starts_on->format('Y');
        $prefix = Institution::profile()->admission_prefix;

        do {
            $no = sprintf('%s/%s/%04d', $prefix, $year, Numbering::next('ADM', $year));
        } while (Student::where('admission_no', $no)->exists());   // skip numbers used by imported records

        return $no;
    }

    public function update(Student $student, array $data): Student
    {
        $d = Rules::check($data, self::profileRules($student) + [
            'admission_no' => ['required', 'string', 'max:30', Rules::unique('students', 'admission_no', $student->id)],
        ]);
        $before = $student->only(array_keys($d));
        $student->update($d);
        Audit::record('student.updated', $student, ['changed' => array_keys(array_diff_assoc(array_map('strval', $d), array_map('strval', $before)))]);

        return $student;
    }

    /* ---------- Guardians ---------- */

    /**
     * Adds a guardian to a student. A guardian with the same phone and name already on file is reused,
     * which links siblings to the same parent record.
     */
    public function linkGuardian(Student $student, array $g): Guardian
    {
        $g = Rules::check($g, self::guardianRules());
        $guardian = Guardian::where('phone', $g['phone'])->get()->first(fn ($x) => strcasecmp(trim($x->name), trim($g['name'])) === 0)
            ?? Guardian::create(collect($g)->only(['name', 'phone', 'alt_phone', 'email', 'occupation', 'address'])->all());

        if (StudentGuardian::where('student_id', $student->id)->where('guardian_id', $guardian->id)->exists()) {
            throw new AcademicRuleException("{$guardian->name} is already linked to this student.", 'name');
        }
        $primary = (bool) ($g['is_primary'] ?? false) || ! StudentGuardian::where('student_id', $student->id)->exists();
        if ($primary) {
            StudentGuardian::where('student_id', $student->id)->update(['is_primary' => false]);
        }
        StudentGuardian::create(['student_id' => $student->id, 'guardian_id' => $guardian->id, 'relation' => $g['relation'],
            'is_primary' => $primary, 'can_pickup' => (bool) ($g['can_pickup'] ?? true), 'is_emergency' => true]);

        return $guardian;
    }

    public function updateGuardian(Guardian $guardian, array $data): Guardian
    {
        $d = Rules::check($data, collect(self::guardianRules())->except(['relation', 'is_primary', 'can_pickup'])->all());
        $guardian->update($d);

        return $guardian;
    }

    public function setPrimaryGuardian(Student $student, Guardian $guardian): void
    {
        $link = StudentGuardian::where('student_id', $student->id)->where('guardian_id', $guardian->id)->firstOrFail();
        DB::transaction(function () use ($student, $link) {
            StudentGuardian::where('student_id', $student->id)->update(['is_primary' => false]);
            $link->update(['is_primary' => true]);
        });
    }

    public function unlinkGuardian(Student $student, Guardian $guardian): void
    {
        $links = StudentGuardian::where('student_id', $student->id)->get();
        $link = $links->firstWhere('guardian_id', $guardian->id) ?? throw new AcademicRuleException('This guardian is not linked to the student.');
        if ($links->count() === 1) {
            throw new AcademicRuleException('A student must have at least one parent or guardian.');
        }
        DB::transaction(function () use ($link, $links) {
            $link->delete();
            if ($link->is_primary) {
                $links->firstWhere('id', '!=', $link->id)->update(['is_primary' => true]);
            }
        });
    }

    /* ---------- Mobile app access ---------- */

    /** Gives a guardian a parent login for the app and emails a set-password link. */
    public function inviteGuardian(Guardian $guardian): void
    {
        if (! $guardian->email) {
            throw new AcademicRuleException('Add the guardian’s email address first.', 'email');
        }
        $this->grantApp($guardian, $guardian->email, $guardian->name, 'parent');
    }

    public function inviteStudent(Student $student): void
    {
        if (! $student->email) {
            throw new AcademicRuleException('Add the student’s email address first.', 'email');
        }
        if ($student->status !== 'active') {
            throw new AcademicRuleException('Only current students can get app access.');
        }
        $this->grantApp($student, $student->email, $student->fullName(), 'student');
    }

    private function grantApp(Guardian|Student $record, string $email, string $name, string $role): void
    {
        $user = DB::transaction(function () use ($record, $email, $name, $role) {
            $user = \Modules\Identity\Models\User::firstOrCreate(['email' => strtolower($email)], ['name' => $name, 'password' => \Illuminate\Support\Str::random(48)]);
            $m = \Modules\Identity\Models\TenantMembership::where('user_id', $user->id)->first();
            if ($m && $m->role !== $role) {
                throw new AcademicRuleException("{$email} already has a {$m->role} account here. Use another email.", 'email');
            }
            if ($record->user_id && $record->user_id !== $user->id) {
                throw new AcademicRuleException('This record already has app access with another email.', 'email');
            }
            \Modules\Identity\Models\TenantMembership::updateOrCreate(['user_id' => $user->id], ['role' => $role, 'status' => 'active']);
            $record->update(['user_id' => $user->id]);
            Audit::record('app_access.granted', $record, ['role' => $role, 'email' => $user->email]);

            return $user;
        });
        SetupService::invite($user, $role);
    }

    /* ---------- Enrolment ---------- */

    public function enroll(Student $student, Section $section, ?int $rollNo = null, ?\DateTimeInterface $joinedOn = null): Enrollment
    {
        $section->loadMissing('session');
        if ($student->status !== 'active') {
            throw new AcademicRuleException("{$student->fullName()} is not an active student.");
        }
        if (! $section->is_active || $section->session->status === 'closed') {
            throw new AcademicRuleException('This '.strtolower(Institution::term('section')).' is not open for enrolment.', 'section_id');
        }
        if ($section->branch_id !== $student->branch_id) {
            throw new AcademicRuleException('This '.strtolower(Institution::term('section')).' is in another branch. Use a branch transfer.', 'section_id');
        }
        if (Enrollment::where('student_id', $student->id)->where('session_id', $section->session_id)->where('status', 'active')->exists()) {
            throw new AcademicRuleException("{$student->fullName()} is already enrolled in session {$section->session->name}.");
        }
        $this->checkCapacity($section, 1);
        if ($rollNo !== null && Enrollment::where('section_id', $section->id)->where('status', 'active')->where('roll_no', $rollNo)->exists()) {
            throw new AcademicRuleException("Roll number {$rollNo} is already taken in this ".strtolower(Institution::term('section')).'.', 'roll_no');
        }

        return Enrollment::create([
            'student_id' => $student->id, 'session_id' => $section->session_id, 'section_id' => $section->id,
            'roll_no' => $rollNo, 'status' => 'active', 'joined_on' => $joinedOn ?? today(),
        ]);
    }

    public function checkCapacity(Section $section, int $adding): void
    {
        if ($section->capacity !== null && $section->activeCount() + $adding > $section->capacity) {
            throw new AcademicRuleException("{$section->label()} is full ({$section->capacity} students).", 'section_id');
        }
    }

    /** Numbers students 1, 2, 3… in alphabetical order (or by admission number). */
    public function assignRollNumbers(Section $section, string $order = 'name'): int
    {
        $rows = Enrollment::query()->select('enrollments.*')->join('students', 'students.id', '=', 'enrollments.student_id')
            ->where('enrollments.section_id', $section->id)->where('enrollments.status', 'active')
            ->when($order === 'admission', fn ($q) => $q->orderBy('students.admission_no'), fn ($q) => $q->orderBy('students.first_name')->orderBy('students.last_name'))
            ->get();

        DB::transaction(function () use ($rows, $section) {
            Enrollment::where('section_id', $section->id)->where('status', 'active')->update(['roll_no' => null]);
            foreach ($rows->values() as $i => $e) {
                Enrollment::whereKey($e->id)->update(['roll_no' => $i + 1]);
            }
        });
        Audit::record('section.roll_numbers_assigned', $section, ['count' => $rows->count(), 'order' => $order]);

        return $rows->count();
    }

    public function setRollNumber(Enrollment $enrollment, ?int $rollNo): void
    {
        if ($rollNo !== null && Enrollment::where('section_id', $enrollment->section_id)->where('status', 'active')
            ->where('roll_no', $rollNo)->whereKeyNot($enrollment->id)->exists()) {
            throw new AcademicRuleException("Roll number {$rollNo} is already taken.", 'roll_no');
        }
        $enrollment->update(['roll_no' => $rollNo]);
    }

    /** Students actively enrolled in a section on a date (handles mid-year joins and moves). */
    public static function rosterOn(Section $section, string $date)
    {
        return Enrollment::with('student')->where('section_id', $section->id)
            ->where('joined_on', '<=', $date)
            ->where(fn ($q) => $q->where('status', 'active')->orWhere('left_on', '>=', $date))
            ->get()->sortBy(fn ($e) => [$e->roll_no ?? 9999, $e->student->first_name])->values();
    }
}
