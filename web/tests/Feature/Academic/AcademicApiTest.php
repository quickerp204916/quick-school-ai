<?php

namespace Tests\Feature\Academic;

use Modules\Academic\Models\Guardian;
use Modules\Academic\Models\Student;
use Modules\Identity\Models\TenantMembership;
use Modules\Identity\Models\User;

/** JSON API for the Flutter app. */
class AcademicApiTest extends AcademicTestCase
{
    private function tokenFor(User $user, ?\Modules\Platform\Models\Tenant $tenant = null): string
    {
        $user->forceFill(['password' => 'Secret-pass-123'])->save();

        return $this->postJson($this->api('auth/token', $tenant), ['email' => $user->email, 'password' => 'Secret-pass-123', 'device_name' => 'Pixel 8'])
            ->assertCreated()->json('token');
    }

    /** A parent account linked to one student. */
    private function parentOf(Student $student): User
    {
        $user = $this->member('parent');
        $this->in(fn () => Guardian::whereHas('students', fn ($q) => $q->whereKey($student->id))->firstOrFail()->update(['user_id' => $user->id]));

        return $user;
    }

    public function test_sign_in_and_profile(): void
    {
        $this->postJson($this->api('auth/token'), ['email' => 'x@y.test', 'password' => 'nope', 'device_name' => 'x'])->assertUnprocessable();
        $token = $this->tokenFor($this->ownerUser());
        $this->withToken($token)->getJson($this->api('me'))->assertOk()->assertJsonPath('role', 'owner');
        $this->withToken($token)->deleteJson($this->api('auth/token'))->assertOk();
    }

    public function test_a_token_only_works_for_its_institution(): void
    {
        $token = $this->tokenFor($this->ownerUser());
        $other = $this->provision();
        $this->in(fn () => TenantMembership::create(['user_id' => $this->ownerUser()->id, 'role' => 'admin', 'status' => 'active']), $other);

        $this->withToken($token)->getJson($this->api('students', $other))->assertForbidden()->assertJsonPath('message', 'This token was issued for another institution.');
    }

    public function test_parent_sees_only_their_child(): void
    {
        $section = $this->section();
        $mine = $this->student($section, ['first_name' => 'Mine']);
        $other = $this->student($section, ['first_name' => 'Other']);
        $token = $this->tokenFor($this->parentOf($mine));

        $this->withToken($token)->getJson($this->api('me'))->assertOk()->assertJsonCount(1, 'students')->assertJsonPath('students.0.first_name', 'Mine');
        $this->withToken($token)->getJson($this->api("students/{$mine->id}/attendance"))->assertOk();
        $this->withToken($token)->getJson($this->api("students/{$other->id}/attendance"))->assertForbidden();
        $this->withToken($token)->getJson($this->api("students/{$other->id}/report-cards"))->assertForbidden();
        $this->withToken($token)->getJson($this->api("sections/{$section->id}/timetable"))->assertOk();
    }

    public function test_teacher_marks_attendance_and_lists_only_their_students(): void
    {
        $teacher = $this->member('teacher');
        $mine = $this->section('Class 5', 'A', ['class_teacher_id' => $this->staffOf($teacher)->id]);
        $other = $this->section('Class 5', 'B');
        $s = $this->student($mine);
        $this->student($other, ['first_name' => 'Elsewhere']);
        $token = $this->tokenFor($teacher);

        $this->withToken($token)->getJson($this->api('sections'))->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson($this->api('students'))->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson($this->api("sections/{$other->id}/students"))->assertForbidden();
        $this->withToken($token)->getJson($this->api("attendance?section_id={$mine->id}&date=2026-11-04"))->assertOk()->assertJsonPath('marked', false);
        $this->withToken($token)->postJson($this->api('attendance'), ['section_id' => $mine->id, 'date' => '2026-11-04', 'entries' => [$s->id => 'absent']])->assertCreated();
        $this->withToken($token)->postJson($this->api('attendance'), ['section_id' => $mine->id, 'date' => '2026-11-08', 'entries' => [$s->id => 'present']])
            ->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_front_office_creates_students_and_enquiries(): void
    {
        $section = $this->section();
        $token = $this->tokenFor($this->member('front_office'));
        $this->withToken($token)->postJson($this->api('students'), ['first_name' => 'Api', 'gender' => 'male', 'date_of_birth' => '2016-01-01', 'section_id' => $section->id,
            'guardians' => [['name' => 'Papa', 'relation' => 'father', 'phone' => '9700000001']]])->assertCreated()->assertJsonPath('data.first_name', 'Api');
        $this->withToken($token)->postJson($this->api('admissions'), ['session_id' => $section->session_id, 'branch_id' => $section->branch_id, 'grade_level_id' => $section->grade_level_id,
            'first_name' => 'Lead', 'source' => 'phone', 'guardians' => [['name' => 'Mama', 'relation' => 'mother', 'phone' => '9700000002']]])->assertCreated()->assertJsonPath('data.stage', 'enquiry');
        $this->withToken($token)->postJson($this->api('students'), ['first_name' => ''])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'section_id']);
    }

    public function test_api_ignores_a_browser_session(): void
    {
        $this->asOwner()->getJson($this->api('me'))->assertUnauthorized();
    }

    public function test_student_submits_homework(): void
    {
        $section = $this->section();
        $s = $this->student($section, ['email' => 'kid@family.test']);
        $maths = $this->in(fn () => \Modules\Academic\Models\Subject::where('code', 'MAT')->firstOrFail());
        $this->asOwner();
        $hw = $this->in(fn () => app(\Modules\Academic\Services\HomeworkService::class)->save(['section_id' => $section->id, 'subject_id' => $maths->id, 'title' => 'Sums', 'assigned_on' => '2026-11-04', 'due_on' => '2026-11-05']));
        $kid = $this->member('student');
        $this->in(fn () => $s->update(['user_id' => $kid->id]));
        $token = $this->tokenFor($kid);

        $this->withToken($token)->getJson($this->api("homework?student_id={$s->id}"))->assertOk()->assertJsonPath('data.0.submission_status', 'pending');
        $this->withToken($token)->postJson($this->api("homework/{$hw->id}/submit"), ['student_id' => $s->id, 'content' => 'Done all 10'])->assertCreated()->assertJsonPath('data.status', 'submitted');
    }
}
