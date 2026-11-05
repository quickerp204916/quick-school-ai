<?php

namespace Modules\Academic\Support;

use App\Core\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\SectionSubject;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Models\TimetableEntry;
use Modules\Identity\Models\TenantMembership;
use Modules\Identity\Models\User;

/**
 * The signed-in user's membership in the current institution, their role,
 * and which sections and subjects they teach. Cached per user and tenant.
 */
final class CurrentMember
{
    private ?string $key = null;

    private ?TenantMembership $membership = null;

    private ?StaffMember $staff = null;

    private ?Collection $teaching = null;

    public function user(): ?User
    {
        $user = request()->user() ?? auth()->user();   // auth() covers queued jobs and console code

        return $user instanceof User ? $user : null;
    }

    private function load(): void
    {
        $key = ($this->user()?->id ?? '-').'|'.(TenantContext::id() ?? '-');
        if ($key === $this->key) {
            return;
        }
        $this->key = $key;
        $this->membership = $this->staff = $this->teaching = null;

        if ($this->user() && TenantContext::check()) {
            $this->membership = TenantMembership::where('user_id', $this->user()->id)->where('status', 'active')->first();
            $this->staff = StaffMember::where('user_id', $this->user()->id)->where('status', 'active')->first();
        }
    }

    public function membership(): ?TenantMembership
    {
        $this->load();

        return $this->membership;
    }

    public function role(): ?string
    {
        return $this->membership()?->role;
    }

    public function staff(): ?StaffMember
    {
        $this->load();

        return $this->staff;
    }

    public function can(string $permission): bool
    {
        $role = $this->role();

        return $role !== null && in_array($role, config("academic.permissions")[$permission] ?? [], true);
    }

    public function authorize(string $permission): void
    {
        if (! $this->can($permission)) {
            throw new AuthorizationException('Your role cannot do this.');
        }
    }

    public function seesAllSections(): bool
    {
        return in_array($this->role(), config('academic.all_sections_roles'), true);
    }

    /**
     * Sections this member teaches, with the subjects they teach in each ('*' = class teacher, all subjects).
     *
     * @return Collection<string, list<string>>
     */
    public function teaching(): Collection
    {
        $this->load();
        if ($this->teaching !== null) {
            return $this->teaching;
        }
        $map = collect();
        if ($staff = $this->staff) {
            foreach (Section::where('class_teacher_id', $staff->id)->pluck('id') as $id) {
                $map[$id] = ['*'];
            }
            $rows = SectionSubject::where('teacher_id', $staff->id)->get(['section_id', 'subject_id'])
                ->concat(TimetableEntry::where('teacher_id', $staff->id)->get(['section_id', 'subject_id']));
            foreach ($rows as $r) {
                $map[$r->section_id] = array_values(array_unique([...($map[$r->section_id] ?? []), $r->subject_id]));
            }
        }

        return $this->teaching = $map;
    }

    /** May this member work with the section (and, if given, the subject in it)? */
    public function teaches(Section|string $section, ?string $subjectId = null): bool
    {
        if ($this->seesAllSections()) {
            return true;
        }
        $id = $section instanceof Section ? $section->id : $section;
        $subjects = $this->teaching()[$id] ?? null;
        if ($subjects === null) {
            return false;
        }

        return $subjectId === null || in_array('*', $subjects, true) || in_array($subjectId, $subjects, true);
    }

    /** Throws unless the member may work with the section. */
    public function mustTeach(Section|string $section, ?string $subjectId = null): void
    {
        if (! $this->teaches($section, $subjectId)) {
            throw new AuthorizationException($subjectId ? 'You do not teach this subject in this section.' : 'You do not teach this section.');
        }
    }

    /** @return list<string>|null section ids visible to the member, or null for all */
    public function visibleSectionIds(): ?array
    {
        return $this->seesAllSections() ? null : $this->teaching()->keys()->all();
    }

    /** Students a parent or student account may see (their children, or themselves). */
    public function linkedStudentIds(): array
    {
        $uid = $this->userId();

        return match ($this->role()) {
            'parent' => \Modules\Academic\Models\StudentGuardian::whereIn('guardian_id', \Modules\Academic\Models\Guardian::where('user_id', $uid)->pluck('id'))->pluck('student_id')->all(),
            'student' => \Modules\Academic\Models\Student::where('user_id', $uid)->pluck('id')->all(),
            default => [],
        };
    }

    public function canSeeStudent(\Modules\Academic\Models\Student $student): bool
    {
        if (in_array($this->role(), ['parent', 'student'], true)) {
            return in_array($student->id, $this->linkedStudentIds(), true);
        }
        if (! $this->can('students.view')) {
            return false;
        }
        if ($this->seesAllSections()) {
            return true;
        }

        return \Modules\Academic\Models\Enrollment::where('student_id', $student->id)->where('status', 'active')
            ->whereIn('section_id', $this->visibleSectionIds() ?? [])->exists();
    }

    public function mustSeeStudent(\Modules\Academic\Models\Student $student): void
    {
        if (! $this->canSeeStudent($student)) {
            throw new AuthorizationException('You cannot view this student.');
        }
    }

    public function userId(): ?string
    {
        return $this->user()?->id;
    }
}
