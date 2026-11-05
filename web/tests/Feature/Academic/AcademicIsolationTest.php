<?php

namespace Tests\Feature\Academic;

use App\Core\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Models\Enrollment;
use Modules\Academic\Models\Student;

/** One institution can never see or touch another's academic records. */
class AcademicIsolationTest extends AcademicTestCase
{
    public function test_records_of_another_institution_are_not_found(): void
    {
        $theirs = $this->student($this->section());
        $mine = $this->provision();

        $this->actingAs($this->ownerUser($mine), 'web');
        $this->get($this->at("students/{$theirs->id}", $mine))->assertNotFound();
        $this->get($this->at('students', $mine))->assertOk()->assertDontSee($theirs->fullName());
        $this->assertSame(0, TenantContext::run($mine, fn () => Student::count()));
    }

    public function test_validation_never_accepts_another_institutions_ids(): void
    {
        $theirSection = $this->section();
        $mine = $this->provision();
        $this->actingAs($this->ownerUser($mine), 'web')->post($this->at('students', $mine), ['first_name' => 'X', 'gender' => 'male', 'date_of_birth' => '2016-01-01',
            'section_id' => $theirSection->id, 'guardians' => [['name' => 'Y', 'relation' => 'father', 'phone' => '9811111111']]])->assertSessionHasErrors('section_id');
    }

    public function test_composite_keys_block_cross_institution_enrolment_even_in_raw_sql(): void
    {
        $theirSection = $this->section();
        $mine = $this->provision();
        $this->tenant = $mine;
        $mySection = $this->section();
        $me = $this->student($mySection);

        $this->expectException(QueryException::class);
        TenantContext::run($mine, fn () => DB::table('enrollments')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $mine->id, 'student_id' => $me->id, 'section_id' => $theirSection->id,
            'session_id' => $theirSection->session_id, 'status' => 'left', 'joined_on' => '2026-11-04', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_postgres_rls_hides_academic_rows_from_raw_queries(): void
    {
        $this->requirePostgres();
        $this->student($this->section());
        $mine = $this->provision();

        $this->assertSame(0, TenantContext::run($mine, fn () => DB::table('students')->count()));
        $this->assertSame(0, TenantContext::run($mine, fn () => DB::table('enrollments')->count()));
        $this->assertSame(1, TenantContext::run($this->tenant, fn () => DB::table('students')->count()));
    }
}
