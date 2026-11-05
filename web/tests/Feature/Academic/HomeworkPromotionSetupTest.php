<?php

namespace Tests\Feature\Academic;

use Modules\Academic\Models\Enrollment;
use Modules\Academic\Models\Homework;
use Modules\Academic\Models\HomeworkSubmission;
use Modules\Academic\Models\InstitutionProfile;
use Modules\Academic\Models\ReportCard;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\Subject;
use Modules\Institution\Models\AcademicSession;

class HomeworkPromotionSetupTest extends AcademicTestCase
{
    public function test_homework_set_and_checked(): void
    {
        $section = $this->section();
        [$a, $b] = [$this->student($section), $this->student($section)];
        $maths = $this->in(fn () => Subject::where('code', 'MAT')->firstOrFail());
        $this->asOwner()->post($this->at('homework'), ['section_subject' => "{$section->id}|{$maths->id}", 'title' => 'Exercise 4.2', 'assigned_on' => '2026-11-04', 'due_on' => '2026-11-03'])
            ->assertSessionHasErrors('due_on');
        $this->post($this->at('homework'), ['section_subject' => "{$section->id}|{$maths->id}", 'title' => 'Exercise 4.2', 'assigned_on' => '2026-11-04', 'due_on' => '2026-11-06'])->assertSessionHasNoErrors();
        $hw = $this->in(fn () => Homework::firstOrFail());

        $this->post($this->at("homework/{$hw->id}/check"), ['rows' => [$a->id => ['status' => 'checked', 'grade' => 'A'], $b->id => ['status' => 'pending']]])->assertSessionHasNoErrors();
        $this->get($this->at("homework/{$hw->id}"))->assertSee('1 of 2 done');
        $this->assertSame('A', $this->in(fn () => HomeworkSubmission::where('student_id', $a->id)->value('grade')));
        $this->get($this->at('reports/homework?from=2026-11-01&to=2026-11-30'))->assertSee('50');
    }

    public function test_homework_only_for_a_subject_taught_in_the_section(): void
    {
        $section = $this->section('Nursery');
        $science = $this->in(fn () => Subject::where('code', 'SCI')->firstOrFail());
        $this->asOwner()->post($this->at('homework'), ['section_subject' => "{$section->id}|{$science->id}", 'title' => 'X', 'assigned_on' => '2026-11-04', 'due_on' => '2026-11-05'])
            ->assertSessionHasErrors();
    }

    public function test_promotion_to_the_next_session(): void
    {
        $from = $this->section('Class 5', 'A');
        [$pass, $fail, $later] = [$this->student($from, ['first_name' => 'Pass']), $this->student($from, ['first_name' => 'Fail']), $this->student($from, ['first_name' => 'Later'])];
        $this->asOwner()->post($this->at('setup/sessions'), ['name' => '2027-28', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31'])->assertSessionHasNoErrors();
        $next = $this->in(fn () => AcademicSession::where('name', '2027-28')->firstOrFail());
        $six = $this->section('Class 6', 'A', ['session_id' => $next->id]);
        $five = $this->section('Class 5', 'A', ['session_id' => $next->id]);
        $this->in(fn () => ReportCard::create(['student_id' => $fail->id, 'section_id' => $from->id, 'session_id' => $from->session_id, 'title' => 'Annual', 'exams' => [], 'data' => [],
            'percentage' => 21, 'result' => 'fail', 'published_at' => now()]));

        $this->get($this->at("promotion?from_session={$from->session_id}&to_session={$next->id}&section={$from->id}"))
            ->assertOk()->assertSee('Result fail in Annual')->assertSee('Class 5 – A → Class 6');

        $this->post($this->at('promotion'), ['section_id' => $from->id, 'to_session_id' => $next->id, 'decisions' => [
            $pass->id => ['action' => 'promote', 'section_id' => $six->id],
            $fail->id => ['action' => 'retain', 'section_id' => $five->id],
            $later->id => ['action' => 'skip'],
        ]])->assertSessionHasNoErrors()->assertSessionHas('status', 'Done: 1 promoted, 1 retained, 0 graduated, 0 left, 1 left for later.');

        $this->in(function () use ($pass, $fail, $later, $six, $five, $from) {
            $this->assertSame($six->id, Enrollment::where('student_id', $pass->id)->where('status', 'active')->value('section_id'));
            $this->assertSame($five->id, Enrollment::where('student_id', $fail->id)->where('status', 'active')->value('section_id'));
            $this->assertSame('promoted', Enrollment::where('student_id', $pass->id)->where('section_id', $from->id)->value('status'));
            $this->assertSame($from->id, Enrollment::where('student_id', $later->id)->where('status', 'active')->value('section_id'));
        });

        // Promoting into the wrong class is refused.
        $this->post($this->at('promotion'), ['section_id' => $from->id, 'to_session_id' => $next->id, 'decisions' => [$later->id => ['action' => 'promote', 'section_id' => $five->id]]])
            ->assertSessionHasErrors();
    }

    public function test_class_12_students_graduate(): void
    {
        $from = $this->section('Class 12');
        $s = $this->student($from);
        $this->asOwner()->post($this->at('setup/sessions'), ['name' => '2027-28', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31']);
        $next = $this->in(fn () => AcademicSession::where('name', '2027-28')->firstOrFail());
        $this->post($this->at('promotion'), ['section_id' => $from->id, 'to_session_id' => $next->id, 'decisions' => [$s->id => ['action' => 'graduate']]])->assertSessionHasNoErrors();
        $this->assertSame('alumni', $this->in(fn () => $s->fresh()->status));
    }

    public function test_sessions_cannot_overlap_and_only_one_is_current(): void
    {
        $this->asOwner()->post($this->at('setup/sessions'), ['name' => 'Overlap', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'])->assertSessionHasErrors();
        $this->post($this->at('setup/sessions'), ['name' => '2027-28', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31'])->assertSessionHasNoErrors();
        $next = $this->in(fn () => AcademicSession::where('name', '2027-28')->firstOrFail());
        $this->post($this->at("setup/sessions/{$next->id}/current"))->assertSessionHasNoErrors();
        $this->assertSame(['2027-28'], $this->in(fn () => AcademicSession::where('is_current', true)->pluck('name')->all()));
    }

    public function test_branch_limit_comes_from_the_plan(): void
    {
        $this->asOwner()->post($this->at('setup/branches'), ['code' => 'EAST', 'name' => 'East campus', 'is_active' => 1])->assertSessionHasErrors();
    }

    public function test_staff_with_login_get_an_invitation_and_a_role(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->asOwner()->post($this->at('staff'), ['employee_code' => 'T001', 'name' => 'Meena Iyer', 'email' => 'meena@school.test', 'is_teaching' => 1, 'create_login' => 1, 'role' => 'teacher'])
            ->assertSessionHasNoErrors();
        \Illuminate\Support\Facades\Mail::assertSent(\Modules\Academic\Mail\AccountInvitation::class);
        $this->assertSame('teacher', $this->in(fn () => \Modules\Identity\Models\TenantMembership::whereHas('user', fn ($q) => $q->where('email', 'meena@school.test'))->value('role')));
        $this->post($this->at('staff'), ['employee_code' => 'T001', 'name' => 'Duplicate'])->assertSessionHasErrors('employee_code');
    }

    public function test_structure_words_change_with_the_institution_type(): void
    {
        $this->institution('coaching');
        $this->asOwner()->get($this->at('sections'))->assertSee('Batches')->assertDontSee('Sections');
        $this->assertSame('coaching', $this->in(fn () => InstitutionProfile::value('structure')));

        $this->institution('college');
        $this->asOwner()->get($this->at('setup/structure'))->assertSee('Programmes')->assertSee('Semesters');
    }

    public function test_grading_scheme_needs_a_band_from_zero(): void
    {
        $this->asOwner()->post($this->at('setup/grading'), ['name' => 'Bad', 'type' => 'grades', 'pass_percent' => 40, 'bands' => [['grade' => 'A', 'min_percent' => 80]]])
            ->assertSessionHasErrors();
    }
}
