<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Services\AcademicReports;
use Modules\Academic\Services\AdmissionService;
use Modules\Academic\Support\Csv;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;

class ReportController extends Controller
{
    public function __construct(private readonly AcademicReports $reports)
    {
    }

    public function index(): View
    {
        return view('academic::reports.index');
    }

    public function strength(Request $request)
    {
        $session = $this->session();
        $rows = $this->reports->strength($session->id, $request->input('branch'));
        if ($request->input('format') === 'csv') {
            return Csv::download('strength.csv', ['Section', 'Branch', 'Class teacher', 'Boys', 'Girls', 'Other', 'Total', 'Capacity', 'Vacant'],
                $rows->map(fn ($r) => [$r['section']->label(), $r['section']->branch->name, $r['section']->classTeacher?->name, $r['male'], $r['female'], $r['other'], $r['total'], $r['capacity'], $r['vacant']])->all());
        }

        return view('academic::reports.strength', ['rows' => $rows, 'session' => $session, 'sessions' => AcademicSession::orderByDesc('starts_on')->get(), 'branches' => Branch::orderBy('name')->get()]);
    }

    public function admissionRegister(Request $request)
    {
        $session = $this->session();
        $rows = $this->reports->admissionRegister($session->id);
        if ($request->input('format') === 'csv') {
            return Csv::download('admission-register.csv', ['Admission no', 'Name', 'Gender', 'Date of birth', 'Admitted on', 'Class', 'Father', 'Mother', 'Phone', 'Status'],
                $rows->map(fn ($s) => [$s->admission_no, $s->fullName(), ucfirst($s->gender), $s->date_of_birth->format('Y-m-d'), $s->admitted_on->format('Y-m-d'),
                    $s->enrollments->last()?->section?->label(), $s->guardianBy('father')?->name, $s->guardianBy('mother')?->name, $s->primaryGuardian()?->phone, $s->status])->all());
        }

        return view('academic::reports.admission-register', ['rows' => $rows, 'session' => $session, 'sessions' => AcademicSession::orderByDesc('starts_on')->get()]);
    }

    public function funnel(AdmissionService $admissions): View
    {
        $session = $this->session();

        return view('academic::reports.funnel', ['f' => $admissions->funnel($session->id), 'session' => $session, 'sessions' => AcademicSession::orderByDesc('starts_on')->get()]);
    }

    public function homework(Request $request)
    {
        $from = $request->input('from', today()->subDays(30)->format('Y-m-d'));
        $to = $request->input('to', today()->format('Y-m-d'));
        $rows = $this->reports->homework($from, $to, $this->member()->visibleSectionIds());
        if ($request->input('format') === 'csv') {
            return Csv::download('homework.csv', ['Section', 'Subject', 'Set', 'Expected', 'Done', 'Percent'],
                $rows->map(fn ($r) => [$r['section']->label(), $r['subject']->name, $r['set'], $r['expected'], $r['done'], $r['percent']])->all());
        }

        return view('academic::reports.homework', compact('rows', 'from', 'to'));
    }
}
