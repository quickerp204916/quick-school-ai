<?php

namespace Tests\Feature\Academic;

use Illuminate\Support\Facades\Event;
use Modules\Academic\Events\StudentsMarkedAbsent;
use Modules\Academic\Models\AttendanceEntry;
use Modules\Academic\Models\BellPeriod;
use Modules\Academic\Models\Holiday;
use Modules\Academic\Models\Subject;
use Modules\Academic\Services\AttendanceService;

class AttendanceAndTimetableTest extends AcademicTestCase
{
    public function test_marking_attendance_counts_and_notifies_absentees(): void
    {
        Event::fake([StudentsMarkedAbsent::class]);
        $section = $this->section();
        [$a, $b, $c] = [$this->student($section), $this->student($section), $this->student($section)];

        $this->asOwner()->post($this->at('attendance/mark'), ['section_id' => $section->id, 'date' => '2026-11-04',
            'entries' => [$a->id => ['status' => 'present'], $b->id => ['status' => 'absent', 'remark' => 'Fever'], $c->id => ['status' => 'half_day']]])
            ->assertSessionHasNoErrors();

        Event::assertDispatched(StudentsMarkedAbsent::class, fn ($e) => $e->studentIds === [$b->id]);
        $sum = $this->in(fn () => app(AttendanceService::class)->studentSummary($c->id, '2026-04-01', '2026-11-04'));
        $this->assertSame(50.0, $sum['percent'], 'Half day counts as half');
        $this->get($this->at('attendance?date=2026-11-04'))->assertSee('1 of 1');

        // Saving again replaces the register instead of adding a second one.
        $this->post($this->at('attendance/mark'), ['section_id' => $section->id, 'date' => '2026-11-04',
            'entries' => [$a->id => 'present', $b->id => 'present', $c->id => 'present']])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->in(fn () => AttendanceEntry::count()));
    }

    public function test_every_student_must_be_marked(): void
    {
        $section = $this->section();
        $a = $this->student($section);
        $this->student($section);
        $this->asOwner()->post($this->at('attendance/mark'), ['section_id' => $section->id, 'date' => '2026-11-04', 'entries' => [$a->id => 'present']])
            ->assertSessionHasErrors('entries');
    }

    public function test_dates_that_cannot_be_marked(): void
    {
        $section = $this->section();
        $a = $this->student($section);
        $this->in(fn () => Holiday::create(['name' => 'Diwali', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11']));
        $this->asOwner();
        foreach (['2026-11-05' => 'future', '2026-11-01' => 'not a working day', '2026-03-30' => 'outside session'] as $date => $why) {
            $this->post($this->at('attendance/mark'), ['section_id' => $section->id, 'date' => $date, 'entries' => [$a->id => 'present']])->assertSessionHasErrors('date');
        }
        $this->travelTo('2026-11-10 10:00');
        $this->post($this->at('attendance/mark'), ['section_id' => $section->id, 'date' => '2026-11-10', 'entries' => [$a->id => 'present']])
            ->assertSessionHasErrors(['date' => '10 Nov 2026 is a holiday: Diwali.']);
    }

    public function test_teachers_mark_only_their_sections_and_only_recent_days(): void
    {
        $teacher = $this->member('teacher');
        $mine = $this->section('Class 5', 'A', ['class_teacher_id' => $this->staffOf($teacher)->id]);
        $other = $this->section('Class 5', 'B');
        $s1 = $this->student($mine);
        $s2 = $this->student($other);

        [$mineLabel, $otherLabel] = $this->in(fn () => [$mine->label(), $other->label()]);
        $this->actingAs($teacher, 'web');
        $this->get($this->at("attendance/mark?section={$other->id}"))->assertForbidden();
        $this->get($this->at('attendance'))->assertSee($mineLabel)->assertDontSee($otherLabel);
        $this->post($this->at('attendance/mark'), ['section_id' => $mine->id, 'date' => '2026-11-04', 'entries' => [$s1->id => 'present']])->assertSessionHasNoErrors();
        // Older than the 3-day edit window: only administrators.
        $this->post($this->at('attendance/mark'), ['section_id' => $mine->id, 'date' => '2026-10-27', 'entries' => [$s1->id => 'present']])->assertSessionHasErrors('date');
        $this->asOwner()->post($this->at('attendance/mark'), ['section_id' => $mine->id, 'date' => '2026-10-27', 'entries' => [$s1->id => 'present']])->assertSessionHasNoErrors();
    }

    public function test_period_attendance_mode(): void
    {
        $section = $this->section();
        $a = $this->student($section);
        $this->in(fn () => \Modules\Academic\Models\InstitutionProfile::query()->update(['attendance_mode' => 'period']));
        \Modules\Academic\Support\Institution::forget();
        $period = $this->in(fn () => BellPeriod::where('is_break', false)->orderBy('sequence')->firstOrFail());

        $this->asOwner()->post($this->at('attendance/mark'), ['section_id' => $section->id, 'date' => '2026-11-04', 'entries' => [$a->id => 'present']])->assertSessionHasErrors('period_id');
        $r = $this->post($this->at('attendance/mark'), ['section_id' => $section->id, 'date' => '2026-11-04', 'period_id' => $period->id, 'entries' => [$a->id => 'absent']]);
        $this->assertNull($r->exception, (string) $r->exception?->getMessage());
        $r->assertSessionHasNoErrors();
        $this->get($this->at("attendance/register?section={$section->id}&month=2026-11"))->assertOk()->assertSee('<td class="A">A</td>', false);
    }

    public function test_low_attendance_report(): void
    {
        $section = $this->section();
        $good = $this->student($section, ['first_name' => 'Regular']);
        $poor = $this->student($section, ['first_name' => 'Often']);
        $this->asOwner();
        foreach (['2026-11-02', '2026-11-03', '2026-11-04'] as $i => $d) {
            $this->post($this->at('attendance/mark'), ['section_id' => $section->id, 'date' => $d, 'entries' => [$good->id => 'present', $poor->id => $i ? 'absent' : 'present']])->assertSessionHasNoErrors();
        }
        $this->get($this->at('attendance/low?from=2026-11-01&to=2026-11-04'))->assertSee('Often')->assertDontSee('Regular')->assertSee('33.33');
    }

    public function test_timetable_refuses_teacher_and_room_clashes(): void
    {
        $teacher = $this->staffOf($this->member('teacher'));
        $a = $this->section('Class 5', 'A');
        $b = $this->section('Class 5', 'B');
        $maths = $this->in(fn () => Subject::where('code', 'MAT')->firstOrFail());
        $period = $this->in(fn () => BellPeriod::where('is_break', false)->orderBy('sequence')->firstOrFail());
        $this->asOwner();
        $this->post($this->at("sections/{$a->id}/subjects"), ['subject_id' => $maths->id, 'teacher_id' => $teacher->id, 'periods_per_week' => 1])->assertSessionHasNoErrors();
        $this->post($this->at("sections/{$b->id}/subjects"), ['subject_id' => $maths->id, 'teacher_id' => $teacher->id])->assertSessionHasNoErrors();

        $this->post($this->at("timetable/{$a->id}"), ['day_of_week' => 1, 'period_id' => $period->id, 'subject_id' => $maths->id, 'room' => '101'])->assertSessionHasNoErrors();
        $this->post($this->at("timetable/{$b->id}"), ['day_of_week' => 1, 'period_id' => $period->id, 'subject_id' => $maths->id])
            ->assertSessionHasErrors(['teacher_id' => "{$teacher->name} already teaches Class 5 – A on Monday, {$period->name}."]);
        $this->post($this->at("timetable/{$a->id}"), ['day_of_week' => 2, 'period_id' => $period->id, 'subject_id' => $maths->id])
            ->assertSessionHasErrors('subject_id'); // only 1 period a week
        $this->post($this->at("timetable/{$a->id}"), ['day_of_week' => 7, 'period_id' => $period->id, 'subject_id' => $maths->id])->assertSessionHasErrors('day_of_week');

        $this->get($this->at("timetable?section={$a->id}"))->assertSee('Mathematics');
        $this->get($this->at("timetable/teacher?staff={$teacher->id}"))->assertSee('Class 5 – A')->assertSee('1 periods a week');
    }
}
