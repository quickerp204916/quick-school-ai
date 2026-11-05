<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Models\TimetableEntry;
use Modules\Academic\Services\TimetableService;

class TimetableController extends Controller
{
    public function __construct(private readonly TimetableService $timetable)
    {
    }

    public function section(Request $request): View
    {
        $session = $this->session();
        $visible = $this->member()->visibleSectionIds();
        $sections = Section::with('level')->where('session_id', $session->id)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->get()->sortBy(fn ($s) => [$s->level->sequence, $s->name])->values();
        $section = $request->filled('section') ? $sections->firstWhere('id', $request->section) : $sections->first();

        return view('academic::timetable.section', ['sections' => $sections, 'section' => $section, 'session' => $session,
            'grid' => $section ? $this->timetable->sectionGrid($section) : null,
            'offered' => $section ? $section->subjects()->with('subject', 'teacher')->get() : collect(),
            'teachers' => StaffMember::where('status', 'active')->where('is_teaching', true)->orderBy('name')->get(),
            'canEdit' => $this->member()->can('timetable.manage')]);
    }

    public function teacher(Request $request): View
    {
        $session = $this->session();
        $teachers = StaffMember::where('status', 'active')->where('is_teaching', true)->orderBy('name')->get();
        $teacher = $request->filled('staff') ? $teachers->firstWhere('id', $request->staff) : ($this->member()->staff() ?? $teachers->first());

        return view('academic::timetable.teacher', ['teachers' => $teachers, 'teacher' => $teacher, 'session' => $session,
            'grid' => $teacher ? $this->timetable->teacherGrid($teacher, $session->id) : null]);
    }

    public function set(Request $request, Section $section): RedirectResponse
    {
        $this->timetable->set($section, $request->all());

        return $this->done('Timetable updated.');
    }

    public function clear(TimetableEntry $entry): RedirectResponse
    {
        $this->timetable->clear($entry);

        return $this->done('Slot cleared.');
    }
}
