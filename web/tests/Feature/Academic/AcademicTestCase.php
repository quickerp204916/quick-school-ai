<?php

namespace Tests\Feature\Academic;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\Academic\Models\GradeLevel;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Models\Student;
use Modules\Academic\Services\SetupService;
use Modules\Academic\Services\StudentService;
use Modules\Academic\Support\Institution;
use Modules\Identity\Models\TenantMembership;
use Modules\Identity\Models\User;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;
use Modules\Platform\Models\Tenant;
use Tests\Feature\Platform\PlatformTestCase;

/** An institution provisioned through the Super Admin flow, with helpers to act inside it. Today is Wed 4 Nov 2026. */
abstract class AcademicTestCase extends PlatformTestCase
{
    protected Tenant $tenant;

    protected int $phone = 9810000000;

    protected function setUp(): void
    {
        parent::setUp();
        Institution::forget();
        $this->tenant = $this->provision();
    }

    protected function institution(string $type, array $overrides = []): Tenant
    {
        Institution::forget();

        return $this->tenant = $this->provision(['type' => $type] + $overrides);
    }

    /** @template T @param callable(): T $fn @return T */
    protected function in(callable $fn, ?Tenant $tenant = null): mixed
    {
        return TenantContext::run($tenant ?? $this->tenant, $fn);
    }

    protected function at(string $path = '', ?Tenant $tenant = null): string
    {
        return rtrim('http://'.($tenant ?? $this->tenant)->code.'.quickschool.app/'.ltrim($path, '/'), '/');
    }

    protected function api(string $path, ?Tenant $tenant = null): string
    {
        return $this->at('api/v1/'.ltrim($path, '/'), $tenant);
    }

    protected function ownerUser(?Tenant $tenant = null): User
    {
        return $this->in(fn () => TenantMembership::where('role', 'owner')->firstOrFail()->user, $tenant);
    }

    protected function asOwner(): static
    {
        return $this->actingAs($this->ownerUser(), 'web');
    }

    /** A signed-in member with a role. Staff roles also get a staff record. */
    protected function member(string $role, array $staff = []): User
    {
        $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::lower(Str::random(8)).'@member.test', 'password' => 'Secret-pass-123']);
        $this->in(function () use ($user, $role, $staff) {
            TenantMembership::create(['user_id' => $user->id, 'role' => $role, 'status' => 'active']);
            if (! in_array($role, ['parent', 'student'], true)) {
                StaffMember::create($staff + ['user_id' => $user->id, 'employee_code' => 'E'.random_int(1000, 99999), 'name' => $user->name,
                    'is_teaching' => $role === 'teacher', 'status' => 'active', 'email' => $user->email]);
            }
        });

        return $user;
    }

    protected function staffOf(User $user): StaffMember
    {
        return $this->in(fn () => StaffMember::where('user_id', $user->id)->firstOrFail());
    }

    protected function currentSession(): AcademicSession
    {
        return $this->in(fn () => AcademicSession::current());
    }

    protected function level(string $name = 'Class 5'): GradeLevel
    {
        return $this->in(fn () => GradeLevel::where('name', $name)->firstOrFail());
    }

    protected function section(string $level = 'Class 5', string $name = 'A', array $extra = []): Section
    {
        return $this->in(fn () => app(SetupService::class)->saveSection($extra + [
            'session_id' => AcademicSession::current()->id, 'branch_id' => Branch::where('is_primary', true)->value('id'),
            'grade_level_id' => GradeLevel::where('name', $level)->value('id'), 'name' => $name, 'is_active' => true,
        ]));
    }

    protected function student(Section $section, array $extra = [], array $guardian = []): Student
    {
        $this->phone++;

        return $this->in(fn () => app(StudentService::class)->create($extra + [
            'first_name' => 'Student'.$this->phone % 1000, 'last_name' => 'Kumar', 'gender' => 'female', 'date_of_birth' => '2015-06-15',
            'section_id' => $section->id, 'admitted_on' => '2026-04-05',
            'guardians' => [$guardian + ['name' => 'Ramesh Kumar', 'relation' => 'father', 'phone' => (string) $this->phone, 'email' => "parent{$this->phone}@family.test"]],
        ]));
    }
}
