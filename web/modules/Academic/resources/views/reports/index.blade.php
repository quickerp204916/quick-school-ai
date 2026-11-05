@extends('academic::layout')
@section('title', 'Reports')
@section('content')
<div class="head"><h1>Reports</h1></div>
<div class="grid">
    @foreach ([
        ['app.reports.strength', 'Class strength', 'Students per '.strtolower($t('section')).' by gender, against capacity.'],
        ['app.reports.admission-register', 'Admission register', 'Every admission in a session with parents and status.'],
        ['app.reports.funnel', 'Admissions funnel', 'Enquiries by stage and source, and conversion.'],
        ['app.attendance.index', 'Daily attendance', 'Which '.strtolower($t('sections')).' are marked, present and absent.'],
        ['app.attendance.low', 'Low attendance', 'Students below the attendance threshold.'],
        ['app.reports.homework', 'Homework completion', 'Homework set and completion by '.strtolower($t('section')).' and '.strtolower($t('subject')).'.'],
        ['app.certificates.index', 'Certificates issued', 'Register of certificates by type and date.'],
        ['app.exams.index', 'Exam results', 'Subject averages, pass rates and toppers per exam.'],
    ] as [$route, $name, $desc])
        @if (\Illuminate\Support\Facades\Route::has($route))
        <a class="card" href="{{ route($route) }}" style="color:inherit"><h2>{{ $name }}</h2><p class="small muted" style="margin:0">{{ $desc }}</p></a>
        @endif
    @endforeach
</div>
<p class="small muted">Each report can be downloaded as CSV for Excel.</p>
@endsection
