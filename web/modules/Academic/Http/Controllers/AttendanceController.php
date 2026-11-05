<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\BellPeriod;
use Modules\Academic\Models\Section;
use Modules\Academic\Services\AttendanceService;
use Modules\Academic\Support\Csv;
use Modules\Academic\Support\Institution;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    /** Today's overview: which sections are marked, present and absent. */
    public function index(Request $request)
    {
        $date = $request->input('date', today()->format('Y-m-d'));
        $session = $this->session();
        $rows = $this->attendance->daySummary($session->id, $date, $this->member()->visibleSectionIds());

        if ($request->input('format') === 'csv') {
            return Csv::download("attendance-{$date}.csv", ['Section', 'Strength', 'Marked', 'Present', 'Absent', 'Late', 'Half day', 'Leave', 'Percent'],
                $rows->map(fn ($r) => [$r['section']->label(), $r['strength'], $r['marked'] ? 'Yes' : 'No', $r['counts']['present'], $r['counts']['absent'],
                    $r['counts']['late'], $r['counts']['half_day'], $r['counts']['leave'], $r['percent']])->all());
        }

        return view('academic::attendance.index', ['rows' => $rows, 'date' => $date, 'session' => $session]);
    }

    public function sheet(Request $request): View
    {
        $section = Section::with('level', 'session', 'subjects.subject')->findOrFail($request->input('section'));
        $this->member()->mustTeach($section);
        $date = $request->input('date', today()->format('Y-m-d'));
        $mode = Institution::profile()->attendance_mode;
        $periods = $mode === 'period' ? BellPeriod::where('branch_id', $section->branch_id)->where('is_break', false)->orderBy('sequence')->get() : collect();
        $periodId = $mode === 'period' ? ($request->input('period') ?: $periods->first()?->id) : null;

        return view('academic::attendance.sheet', ['section' => $section, 'date' => $date, 'mode' => $mode, 'periods' => $periods, 'periodId' => $periodId]
            + $this->attendance->sheet($section, $date, $periodId));
    }

    public function mark(Request $request): RedirectResponse
    {
        $section = Section::findOrFail($request->input('section_id'));
        $this->attendance->mark($section, $request->only(['date', 'period_id', 'subject_id', 'entries']));
        $absent = collect($request->input('entries', []))->filter(fn ($e) => ($e['status'] ?? $e) === 'absent')->count();

        return $this->done('Attendance saved.'.($absent ? " {$absent} absent." : ' Everyone present.'));
    }

    public function register(Request $request)
    {
        $section = Section::with('level')->findOrFail($request->input('section'));
        $this->member()->mustTeach($section);
        $month = $request->input('month', today()->format('Y-m'));
        $data = $this->attendance->monthlyRegister($section, $month);

        if ($request->input('format') === 'csv') {
            $days = $data['days'];

            return Csv::download("register-{$month}.csv", array_merge(['Roll', 'Admission no', 'Name'], array_map(fn ($d) => substr($d, 8), $days), ['Percent']),
                $data['rows']->map(fn ($r) => array_merge([$r['enrollment']->roll_no, $r['student']->admission_no, $r['student']->fullName()],
                    array_map(fn ($d) => $r['cells'][$d] ?? '', $days), [$r['summary']['percent']]))->all());
        }

        return view('academic::attendance.register', ['section' => $section, 'month' => $month] + $data);
    }

    public function low(Request $request)
    {
        $session = $this->session();
        $from = $request->input('from', $session->starts_on->format('Y-m-d'));
        $to = $request->input('to', min(today()->format('Y-m-d'), $session->ends_on->format('Y-m-d')));
        $threshold = (float) $request->input('below', Institution::profile()->low_attendance_percent);
        $rows = $this->attendance->lowAttendance($from, $to, $threshold, $this->member()->visibleSectionIds());

        if ($request->input('format') === 'csv') {
            return Csv::download('low-attendance.csv', ['Admission no', 'Name', 'Section', 'Days marked', 'Absent', 'Percent', 'Guardian phone'],
                $rows->map(fn ($r) => [$r['student']->admission_no, $r['student']->fullName(), $r['student']->currentEnrollment?->section?->label(),
                    $r['total'], $r['counts']['absent'], $r['percent'], $r['student']->primaryGuardian()?->phone])->all());
        }

        return view('academic::attendance.low', compact('rows', 'from', 'to', 'threshold'));
    }
}
