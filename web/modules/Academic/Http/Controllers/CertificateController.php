<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Academic\Models\Certificate;
use Modules\Academic\Models\CertificateTemplate;
use Modules\Academic\Models\Student;
use Modules\Academic\Services\CertificateService;
use Modules\Academic\Support\Csv;
use Modules\Academic\Support\Institution;

class CertificateController extends Controller
{
    public function __construct(private readonly CertificateService $certificates)
    {
    }

    public function index(Request $request)
    {
        $from = $request->input('from', today()->startOfMonth()->subMonths(2)->format('Y-m-d'));
        $to = $request->input('to', today()->format('Y-m-d'));
        $list = Certificate::with('student')->whereBetween('issued_on', [$from, $to])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))->orderByDesc('issued_on')->orderByDesc('number')->get();

        if ($request->input('format') === 'csv') {
            return Csv::download('certificates.csv', ['Number', 'Type', 'Issued on', 'Admission no', 'Student', 'Purpose', 'Status'],
                $list->map(fn ($c) => [$c->number, config("academic.certificate_types.{$c->type}.label"), $c->issued_on->format('Y-m-d'),
                    $c->student->admission_no, $c->student->fullName(), $c->purpose, $c->status])->all());
        }

        return view('academic::certificates.index', compact('list', 'from', 'to'));
    }

    public function create(Request $request): View
    {
        $student = Student::findOrFail($request->input('student'));

        return view('academic::certificates.create', ['s' => $student, 'templates' => CertificateTemplate::where('is_active', true)->orderBy('name')->get(),
            'template' => $request->filled('template') ? CertificateTemplate::find($request->template) : null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $student = Student::findOrFail($request->input('student_id'));
        $cert = $this->certificates->issue($student, $request->all());

        return $this->done("Certificate {$cert->number} issued.", route('app.certificates.show', $cert));
    }

    public function show(Certificate $certificate): View
    {
        return view('academic::certificates.show', ['c' => $certificate->load('student', 'template'), 'tenant' => Institution::tenant(), 'profile' => Institution::profile()]);
    }

    public function cancel(Request $request, Certificate $certificate): RedirectResponse
    {
        $this->certificates->cancel($certificate, (string) $request->input('reason'));

        return $this->done("Certificate {$certificate->number} cancelled.");
    }
}
