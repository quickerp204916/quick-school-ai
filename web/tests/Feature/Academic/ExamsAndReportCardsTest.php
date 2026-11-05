<?php

namespace Tests\Feature\Academic;

use Modules\Academic\Models\Exam;
use Modules\Academic\Models\ExamPaper;
use Modules\Academic\Models\ReportCard;

class ExamsAndReportCardsTest extends AcademicTestCase
{
    /** Class 5 with three students, maths and English papers in two exams. */
    private function setUpExams(): array
    {
        $section = $this->section();
        $students = [$this->student($section, ['first_name' => 'Asha']), $this->student($section, ['first_name' => 'Bala']), $this->student($section, ['first_name' => 'Chitra'])];
        $this->asOwner();
        $exams = [];
        foreach (['Half-yearly' => '2026-09-15', 'Annual' => '2027-02-20'] as $name => $date) {
            $this->post($this->at('exams'), ['session_id' => $section->session_id, 'name' => $name, 'type' => 'term', 'starts_on' => $date])->assertSessionHasNoErrors();
            $exam = $this->in(fn () => Exam::where('name', $name)->firstOrFail());
            $subjects = $this->in(fn () => \Modules\Academic\Models\Subject::whereIn('code', ['MAT', 'ENG'])->pluck('id')->all());
            $this->post($this->at("exams/{$exam->id}/papers"), ['section_ids' => [$section->id], 'subject_ids' => $subjects, 'max_marks' => 100, 'pass_marks' => 33])->assertSessionHasNoErrors();
            $exams[$name] = $exam;
        }

        return [$section, $students, $exams];
    }

    private function marks(Exam $exam, string $code, array $byStudent): \Illuminate\Testing\TestResponse
    {
        $paper = $this->in(fn () => ExamPaper::where('exam_id', $exam->id)->whereHas('subject', fn ($q) => $q->where('code', $code))->firstOrFail());

        return $this->post($this->at("papers/{$paper->id}/marks"), ['entries' => $byStudent]);
    }

    public function test_marks_entry_rules_and_publishing(): void
    {
        [$section, [$a, $b, $c], $exams] = $this->setUpExams();
        $hy = $exams['Half-yearly'];
        $this->assertSame(2, $this->in(fn () => ExamPaper::where('exam_id', $hy->id)->count()));

        $this->marks($hy, 'MAT', [$a->id => ['marks' => '80']])->assertSessionHasErrors(); // not open yet
        $this->post($this->at("exams/{$hy->id}/status"), ['status' => 'open'])->assertSessionHasNoErrors();
        $this->marks($hy, 'MAT', [$a->id => ['marks' => '101']])->assertSessionHasErrors("entries.{$a->id}");
        $this->marks($hy, 'MAT', [$a->id => ['marks' => '12', 'absent' => 1]])->assertSessionHasErrors("entries.{$a->id}");
        $this->marks($hy, 'MAT', [$a->id => ['marks' => '90'], $b->id => ['marks' => '45.5'], $c->id => ['marks' => '', 'absent' => 1]])->assertSessionHasNoErrors();

        $this->post($this->at("exams/{$hy->id}/status"), ['status' => 'published'])->assertSessionHasErrors(); // English missing
        $this->marks($hy, 'ENG', [$a->id => ['marks' => '70'], $b->id => ['marks' => '20'], $c->id => ['marks' => '60']])->assertSessionHasNoErrors();
        $this->post($this->at("exams/{$hy->id}/status"), ['status' => 'published'])->assertSessionHasNoErrors();
        $this->marks($hy, 'MAT', [$a->id => ['marks' => '91']])->assertSessionHasErrors(); // locked once published
        $this->post($this->at("exams/{$hy->id}/status"), ['status' => 'draft'])->assertSessionHasErrors('status');

        $this->get($this->at("exams/{$hy->id}/analysis?section={$section->id}"))->assertOk()->assertSee('67.75')->assertSee('Asha');
    }

    public function test_teacher_enters_marks_only_for_their_subject(): void
    {
        [$section, [$a], $exams] = $this->setUpExams();
        $teacher = $this->member('teacher');
        $maths = $this->in(fn () => \Modules\Academic\Models\Subject::where('code', 'MAT')->firstOrFail());
        $this->post($this->at("sections/{$section->id}/subjects"), ['subject_id' => $maths->id, 'teacher_id' => $this->staffOf($teacher)->id])->assertSessionHasNoErrors();
        $this->post($this->at("exams/{$exams['Half-yearly']->id}/status"), ['status' => 'open']);

        $this->actingAs($teacher, 'web');
        $this->get($this->at('exams'))->assertSee('Your papers to mark');
        $this->marks($exams['Half-yearly'], 'MAT', [$a->id => ['marks' => '50']])->assertSessionHasNoErrors();
        $this->marks($exams['Half-yearly'], 'ENG', [$a->id => ['marks' => '50']])->assertForbidden();
    }

    public function test_weighted_report_cards_with_grades_ranks_and_attendance(): void
    {
        [$section, [$a, $b, $c], $exams] = $this->setUpExams();
        $this->post($this->at('attendance/mark'), ['section_id' => $section->id, 'date' => '2026-11-04', 'entries' => [$a->id => 'present', $b->id => 'absent', $c->id => 'present']]);
        foreach ([['Half-yearly', [80, 70], [40, 30], [80, 70]], ['Annual', [90, 80], [50, 25], [90, 80]]] as [$name, $am, $bm, $cm]) {
            $exam = $exams[$name];
            $this->post($this->at("exams/{$exam->id}/status"), ['status' => 'open']);
            $this->marks($exam, 'MAT', [$a->id => ['marks' => $am[0]], $b->id => ['marks' => $bm[0]], $c->id => ['marks' => $cm[0]]])->assertSessionHasNoErrors();
            $this->marks($exam, 'ENG', [$a->id => ['marks' => $am[1]], $b->id => ['marks' => $bm[1]], $c->id => ['marks' => $cm[1]]])->assertSessionHasNoErrors();
            $this->post($this->at("exams/{$exam->id}/status"), ['status' => 'published'])->assertSessionHasNoErrors();
        }
        $weights = [$exams['Half-yearly']->id => 40, $exams['Annual']->id => 60];

        $this->get($this->at('report-cards?'.http_build_query(['section' => $section->id, 'exams' => $weights])))->assertOk()->assertSee('Preview');
        $this->post($this->at("report-cards/{$section->id}/publish"), ['exams' => $weights, 'title' => 'Annual report 2026-27', 'remarks' => [$a->id => 'Excellent work']])
            ->assertSessionHasNoErrors();

        $cards = $this->in(fn () => ReportCard::get()->keyBy('student_id'));
        // Asha: maths 0.4×80 + 0.6×90 = 86, English 0.4×70 + 0.6×80 = 76 → 81 % → CBSE A2.
        $this->assertSame(['81.00', 'A2', 'pass', 1, '100.00'], [$cards[$a->id]->percentage, $cards[$a->id]->grade, $cards[$a->id]->result, $cards[$a->id]->rank, $cards[$a->id]->attendance_percent]);
        $this->assertSame(1, $cards[$c->id]->rank, 'Equal percentages share a rank');
        // Bala: English 0.4×30 + 0.6×25 = 27 < 33 → fails English, no rank.
        $this->assertSame(['fail', null, ['English']], [$cards[$b->id]->result, $cards[$b->id]->rank, $cards[$b->id]->data['failed_subjects']]);
        $this->assertSame('Excellent work', $cards[$a->id]->remarks);

        $this->get($this->at("report-cards/{$cards[$a->id]->id}"))->assertOk()->assertSee('81.00%')->assertSee('Excellent work');

        // Republishing with the same title replaces, not duplicates.
        $this->post($this->at("report-cards/{$section->id}/publish"), ['exams' => $weights, 'title' => 'Annual report 2026-27']);
        $this->assertSame(3, $this->in(fn () => ReportCard::count()));
    }

    public function test_report_cards_need_published_exams(): void
    {
        [$section, , $exams] = $this->setUpExams();
        $this->post($this->at("report-cards/{$section->id}/publish"), ['exams' => [$exams['Half-yearly']->id => 100], 'title' => 'Term 1'])->assertSessionHasErrors();
    }

    public function test_exam_dates_must_be_in_the_session(): void
    {
        $section = $this->section();
        $this->asOwner()->post($this->at('exams'), ['session_id' => $section->session_id, 'name' => 'Late', 'type' => 'final', 'starts_on' => '2027-04-10'])->assertSessionHasErrors('starts_on');
    }
}
