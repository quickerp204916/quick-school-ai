<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\View\View;
use Modules\Academic\Models\AdmissionApplication;
use Modules\Academic\Models\Exam;
use Modules\Academic\Models\Homework;
use Modules\Academic\Models\Section;
use Modules\Academic\Models\Student;
use Modules\Academic\Services\AttendanceService;
use Modules\Academic\Support\Institution;
use Modules\Institution\Models\AcademicSession;

class DashboardController extends Controller
{
    public function __invoke(AttendanceService $attendance): View
    {
        $session = AcademicSession::current();
        $visible = $this->member()->visibleSectionIds();
        $today = today()->format('Y-m-d');
        $day = $session ? $attendance->daySummary($session->id, $today, $visible) : collect();

        return view('academic::dashboard', [
            'session' => $session, 'tenant' => Institution::tenant(),
            'students' => Student::where('status', 'active')->count(),
            'studentLimit' => Institution::features()->limit('students_max'),
            'sections' => $session ? Section::where('session_id', $session->id)->count() : 0,
            'day' => $day,
            'marked' => $day->where('marked', true)->count(),
            'present' => $day->sum(fn ($r) => $r['counts']['present'] + $r['counts']['late']),
            'absent' => $day->sum(fn ($r) => $r['counts']['absent']),
            'openEnquiries' => $this->member()->can('admissions.manage') ? AdmissionApplication::whereNotIn('stage', ['admitted', 'rejected', 'withdrawn'])->count() : null,
            'followUps' => $this->member()->can('admissions.manage') ? AdmissionApplication::whereNotIn('stage', ['admitted', 'rejected', 'withdrawn'])->whereNotNull('follow_up_on')->where('follow_up_on', '<=', $today)->count() : null,
            'openExams' => $session ? Exam::where('session_id', $session->id)->where('status', 'open')->get() : collect(),
            'dueHomework' => Homework::with(['section.level', 'subject'])->where('status', 'published')->whereBetween('due_on', [$today, today()->addDays(3)->format('Y-m-d')])
                ->when($visible !== null, fn ($q) => $q->whereIn('section_id', $visible))->orderBy('due_on')->limit(8)->get(),
        ]);
    }
}
