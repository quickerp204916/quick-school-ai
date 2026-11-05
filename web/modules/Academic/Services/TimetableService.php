<?php

namespace Modules\Academic\Services;

use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Models\BellPeriod;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\SectionSubject;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Models\TimetableEntry;
use Modules\Academic\Support\Institution;
use Modules\Academic\Support\Rules;

/** Weekly timetable per section, with teacher, room and weekly-load checks. */
final class TimetableService
{
    public function set(Section $section, array $data): TimetableEntry
    {
        $d = Rules::check($data, [
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'period_id' => ['required', Rules::exists('bell_periods', ['branch_id' => $section->branch_id])],
            'subject_id' => ['required', Rules::exists('subjects')],
            'teacher_id' => ['nullable', Rules::exists('staff_members', ['status' => 'active'])],
            'room' => ['nullable', 'string', 'max:40'],
        ], ['period_id.exists' => 'Choose a period from this branch’s bell schedule.']);

        $day = (int) $d['day_of_week'];
        $dayName = TimetableEntry::DAYS[$day];
        if (! Institution::profile()->isWorkingDay(now()->startOfWeek()->addDays($day - 1))) {
            throw new AcademicRuleException("{$dayName} is not a working day.", 'day_of_week');
        }
        $period = BellPeriod::findOrFail($d['period_id']);
        if ($period->is_break) {
            throw new AcademicRuleException("{$period->name} is a break.", 'period_id');
        }
        $offered = SectionSubject::with('subject')->where('section_id', $section->id)->where('subject_id', $d['subject_id'])->first();
        if (! $offered) {
            throw new AcademicRuleException('Add this subject to the section before timetabling it.', 'subject_id');
        }
        $teacherId = $d['teacher_id'] ?? $offered->teacher_id;
        if ($teacherId && ! StaffMember::whereKey($teacherId)->where('is_teaching', true)->exists()) {
            throw new AcademicRuleException('Choose a teaching staff member.', 'teacher_id');
        }

        $slot = TimetableEntry::where('section_id', $section->id)->where('day_of_week', $day)->where('period_id', $period->id)->first();

        if ($teacherId) {
            $busy = TimetableEntry::with('section.level')->where('session_id', $section->session_id)->where('teacher_id', $teacherId)
                ->where('day_of_week', $day)->where('period_id', $period->id)->where('section_id', '!=', $section->id)->first();
            if ($busy) {
                $t = StaffMember::find($teacherId);
                throw new AcademicRuleException("{$t->name} already teaches {$busy->section->label()} on {$dayName}, {$period->name}.", 'teacher_id');
            }
        }
        if (! empty($d['room'])) {
            $taken = TimetableEntry::with('section.level')->where('session_id', $section->session_id)->where('room', $d['room'])
                ->where('day_of_week', $day)->where('period_id', $period->id)->where('section_id', '!=', $section->id)
                ->whereHas('section', fn ($q) => $q->where('branch_id', $section->branch_id))->first();
            if ($taken) {
                throw new AcademicRuleException("Room {$d['room']} is used by {$taken->section->label()} then.", 'room');
            }
        }
        if ($offered->periods_per_week) {
            $count = TimetableEntry::where('section_id', $section->id)->where('subject_id', $d['subject_id'])
                ->when($slot, fn ($q) => $q->whereKeyNot($slot->id))->count();
            if ($count >= $offered->periods_per_week) {
                throw new AcademicRuleException("{$offered->subject->name} already has its {$offered->periods_per_week} periods this week.", 'subject_id');
            }
        }

        $slot ??= new TimetableEntry(['session_id' => $section->session_id, 'section_id' => $section->id, 'day_of_week' => $day, 'period_id' => $period->id]);
        $slot->fill(['subject_id' => $d['subject_id'], 'teacher_id' => $teacherId, 'room' => $d['room'] ?? null])->save();

        return $slot;
    }

    public function clear(TimetableEntry $entry): void
    {
        $entry->delete();
    }

    /** @return array{days: array<int,string>, periods: \Illuminate\Support\Collection, cells: array<string, TimetableEntry>} cells keyed "day|period_id" */
    public function sectionGrid(Section $section): array
    {
        $entries = TimetableEntry::with(['subject', 'teacher'])->where('section_id', $section->id)->get();

        return ['days' => $this->days(), 'periods' => BellPeriod::where('branch_id', $section->branch_id)->orderBy('sequence')->get(),
            'cells' => $entries->keyBy(fn ($e) => $e->day_of_week.'|'.$e->period_id)->all()];
    }

    /** One teacher's week across all sections of a session. */
    public function teacherGrid(StaffMember $teacher, string $sessionId): array
    {
        $entries = TimetableEntry::with(['subject', 'section.level', 'period'])->where('session_id', $sessionId)->where('teacher_id', $teacher->id)->get();
        $branchId = $entries->first()?->section?->branch_id ?? $teacher->branch_id;

        return ['days' => $this->days(), 'periods' => BellPeriod::when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('sequence')->get()->unique('sequence'),
            'cells' => $entries->keyBy(fn ($e) => $e->day_of_week.'|'.$e->period->sequence)->all(), 'load' => $entries->count()];
    }

    private function days(): array
    {
        return collect(Institution::profile()->working_days)->mapWithKeys(fn ($d) => [(int) $d => TimetableEntry::DAYS[(int) $d]])->all();
    }
}
