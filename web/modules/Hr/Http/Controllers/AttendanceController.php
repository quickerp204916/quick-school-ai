<?php

namespace Modules\Hr\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\StaffMember;
use Modules\Hr\Models\Attendance;
use Modules\Hr\Services\AttendanceService;

class AttendanceController extends \Modules\Academic\Http\Controllers\Controller
{
    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    public function index(Request $r): View
    {
        $date = $r->input('date', today()->format('Y-m-d'));

        return view('hr::attendance.index', ['date' => $date, 'staff' => StaffMember::where('status', 'active')->orderBy('name')->get(),
            'marks' => Attendance::where('date', $date)->get()->keyBy('staff_member_id')]);
    }

    public function mark(Request $r): RedirectResponse
    {
        $n = $this->attendance->mark((string) $r->input('date'), (array) $r->input('rows', []));

        return $this->done("Attendance saved for {$n} staff.");
    }

    public function import(Request $r): RedirectResponse
    {
        $csv = $r->hasFile('file') ? file_get_contents($r->file('file')->getRealPath()) : (string) $r->input('csv');
        $res = $this->attendance->import($csv);

        return $this->done("Imported {$res['imported']} punch(es); skipped {$res['skipped']}.");
    }

    public function month(Request $r): View
    {
        $period = $r->input('month', today()->format('Y-m'));
        $staff = StaffMember::where('status', 'active')->orderBy('name')->get();

        return view('hr::attendance.month', ['period' => $period, 'rows' => $staff->map(fn ($s) => ['staff' => $s] + $this->attendance->summary($s, $period))]);
    }
}
