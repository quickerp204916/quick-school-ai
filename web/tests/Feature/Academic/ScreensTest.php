<?php

namespace Tests\Feature\Academic;

use Modules\Academic\Models\CertificateTemplate;
use Modules\Academic\Models\Exam;
use Modules\Academic\Services\AdmissionService;
use Modules\Academic\Services\CertificateService;
use Modules\Academic\Services\ExamService;
use Modules\Academic\Services\HomeworkService;

/** Every screen renders, for each kind of institution, with real data on it. */
class ScreensTest extends AcademicTestCase
{
    public static function structures(): array
    {
        return ['K-12 school' => ['school', 'Section'], 'College' => ['college', 'Section'], 'Coaching institute' => ['coaching', 'Batch']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('structures')]
    public function test_every_screen_renders(string $type, string $sectionWord): void
    {
        $this->institution($type);
        if ($type !== 'school') {
            $this->asOwner()->post($this->at('setup/programs'), ['code' => 'P1', 'name' => 'Programme One', 'is_active' => 1])->assertSessionHasNoErrors()->assertRedirect();
            $program = $this->in(fn () => \Modules\Academic\Models\Program::firstOrFail());
            $this->post($this->at("setup/programs/{$program->id}/levels"), ['code' => 'L1', 'name' => 'Level 5', 'sequence' => 1, 'is_active' => 1])->assertSessionHasNoErrors();
            $this->post($this->at('setup/subjects'), ['code' => 'MAT', 'name' => 'Mathematics', 'type' => 'core', 'is_active' => 1])->assertSessionHasNoErrors();
            $level = $this->level('Level 5');
            $subject = $this->in(fn () => \Modules\Academic\Models\Subject::firstOrFail());
            $this->put($this->at("setup/levels/{$level->id}/subjects"), ['subjects' => [$subject->id]])->assertSessionHasNoErrors();
        }
        $levelName = $type === 'school' ? 'Class 5' : 'Level 5';
        $section = $this->section($levelName);
        $student = $this->student($section);
        $guardian = $this->in(fn () => $student->guardians()->first());
        $app = $this->in(fn () => app(AdmissionService::class)->create([
            'session_id' => $section->session_id, 'branch_id' => $section->branch_id, 'grade_level_id' => $section->grade_level_id,
            'first_name' => 'Asha', 'source' => 'walk_in', 'guardians' => [['name' => 'Mohan', 'relation' => 'father', 'phone' => '9876543210']],
        ]));
        $exam = $this->in(fn () => app(ExamService::class)->saveExam(['session_id' => $section->session_id, 'name' => 'Unit test 1', 'type' => 'unit_test', 'starts_on' => '2026-10-10']));
        $this->in(fn () => app(ExamService::class)->addPapers($exam, ['section_ids' => [$section->id], 'max_marks' => 50, 'pass_marks' => 17]));
        $paper = $this->in(fn () => $exam->papers()->firstOrFail());
        $this->asOwner();
        $hw = $this->in(fn () => app(HomeworkService::class)->save(['section_id' => $section->id, 'subject_id' => $paper->subject_id, 'title' => 'Exercise 1', 'assigned_on' => '2026-11-03', 'due_on' => '2026-11-06']));
        $template = $this->in(fn () => CertificateTemplate::where('type', 'character')->firstOrFail());
        $cert = $this->in(fn () => app(CertificateService::class)->issue($student, ['template_id' => $template->id]));

        $pages = ['/', 'setup', 'setup/sessions', 'setup/structure', 'setup/grading', 'setup/calendar', 'setup/certificates', 'staff',
            'sections', 'sections/create', "sections/{$section->id}", "sections/{$section->id}/edit",
            'admissions', 'admissions/create', "admissions/{$app->id}", "admissions/{$app->id}/edit",
            'students', 'students/create', "students/{$student->id}", "students/{$student->id}/edit", 'guardians', "guardians/{$guardian->id}",
            'attendance', "attendance/mark?section={$section->id}", "attendance/register?section={$section->id}", 'attendance/low',
            "timetable?section={$section->id}", 'timetable/teacher',
            'exams', 'exams/create', "exams/{$exam->id}", "exams/{$exam->id}/edit", "papers/{$paper->id}/marks", "exams/{$exam->id}/analysis",
            'report-cards', "report-cards?section={$section->id}",
            'homework', 'homework/create', "homework/{$hw->id}", "homework/{$hw->id}/edit",
            'certificates', "certificates/create?student={$student->id}", "certificates/{$cert->id}",
            'promotion', "promotion?section={$section->id}",
            'reports', 'reports/strength', 'reports/admission-register', 'reports/admissions', 'reports/homework'];

        foreach ($pages as $page) {
            $res = $this->get($this->at($page));
            $this->assertSame(200, $res->status(), "GET {$page}: ".($res->exception?->getMessage() ?? $res->getContent()));
        }
        $this->get($this->at('sections'))->assertSee($sectionWord);

        foreach (['students?format=csv', 'attendance?format=csv', "attendance/register?section={$section->id}&format=csv", 'attendance/low?format=csv',
            "exams/{$exam->id}/analysis?format=csv", 'certificates?format=csv', 'reports/strength?format=csv', 'reports/admission-register?format=csv', 'reports/homework?format=csv'] as $csv) {
            $this->get($this->at($csv))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        }
    }

    public function test_sign_in_page_and_guest_redirect(): void
    {
        $this->get($this->at('login'))->assertOk()->assertSee($this->tenant->name);
        $this->get($this->at('students'))->assertRedirect($this->at('login'));
    }

    public function test_sign_in_with_password(): void
    {
        $owner = $this->ownerUser();
        $owner->forceFill(['password' => 'Correct-Horse-42'])->save();

        $this->post($this->at('login'), ['email' => $owner->email, 'password' => 'Correct-Horse-42'])->assertRedirect($this->at());
        $this->assertAuthenticatedAs($owner, 'web');
    }

    public function test_a_member_of_one_institution_cannot_sign_in_to_another(): void
    {
        $owner = $this->ownerUser();
        $owner->forceFill(['password' => 'Correct-Horse-42'])->save();
        $other = $this->provision();

        $this->post($this->at('login', $other), ['email' => $owner->email, 'password' => 'Correct-Horse-42'])->assertSessionHasErrors('email');
        $this->actingAs($owner, 'web')->get($this->at('students', $other))->assertForbidden();
    }

    public function test_unknown_and_suspended_institutions(): void
    {
        $this->get('http://no-such-school.quickschool.app/login')->assertNotFound();
        $this->tenant->update(['status' => 'suspended']);
        $this->get($this->at('login'))->assertForbidden()->assertSee('suspended');
    }

    public function test_parents_cannot_use_the_staff_console(): void
    {
        $this->actingAs($this->member('parent'), 'web')->get($this->at('students'))->assertForbidden();
    }

    public function test_teacher_sees_only_permitted_menu_items(): void
    {
        $this->actingAs($this->member('teacher'), 'web');
        $this->get($this->at())->assertOk()->assertDontSee('Institution setup')->assertDontSee('Promotion');
        $this->get($this->at('setup'))->assertForbidden();
        $this->get($this->at('admissions'))->assertForbidden();
    }
}
