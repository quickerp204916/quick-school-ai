<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Home') · {{ \Modules\Academic\Support\Institution::tenant()->name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('css/platform.css') }}" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
</head>
<body>
@php($r = request()->route()?->getName() ?? '')
@php($on = fn (string ...$p) => collect($p)->contains(fn ($x) => str_starts_with($r, 'app.'.$x)) ? 'on' : '')
<div class="shell">
    <nav class="side" aria-label="Main">
        <div class="brand"><span class="mark">Q</span><div><b>QUICK SCHOOL AI</b><span>Smart School. Smarter Management.</span></div></div>
        <div class="inst">{{ \Modules\Academic\Support\Institution::tenant()->name }}</div>
        <a class="nav {{ $r === 'app.dashboard' ? 'on' : '' }}" href="{{ route('app.dashboard') }}">Dashboard</a>
        <div class="group">Students</div>
        @can('academic.admissions.manage')<a class="nav {{ $on('admissions') }}" href="{{ route('app.admissions.index') }}">Admissions</a>@endcan
        @can('academic.students.view')
            <a class="nav {{ $on('students') }}" href="{{ route('app.students.index') }}">Students</a>
            <a class="nav {{ $on('guardians') }}" href="{{ route('app.guardians.index') }}">Guardians</a>
            <a class="nav {{ $on('sections') }}" href="{{ route('app.sections.index') }}">{{ $t('levels') }} and {{ strtolower($t('sections')) }}</a>
        @endcan
        @can('academic.promotion.run')<a class="nav {{ $on('promotion') }}" href="{{ route('app.promotion.index') }}">Promotion</a>@endcan
        @can('academic.certificates.issue')<a class="nav {{ $on('certificates') }}" href="{{ route('app.certificates.index') }}">Certificates</a>@endcan
        <div class="group">Teaching</div>
        @can('academic.attendance.view')<a class="nav {{ $on('attendance') }}" href="{{ route('app.attendance.index') }}">Attendance</a>@endcan
        @can('academic.timetable.view')<a class="nav {{ $on('timetable') }}" href="{{ route('app.timetable.section') }}">Timetable</a>@endcan
        @can('academic.homework.manage')<a class="nav {{ $on('homework') }}" href="{{ route('app.homework.index') }}">Homework</a>@endcan
        @can('academic.marks.enter')<a class="nav {{ $on('exams', 'papers') }}" href="{{ route('app.exams.index') }}">Exams and marks</a>@endcan
        @can('academic.reportcards.publish')<a class="nav {{ $on('report-cards') }}" href="{{ route('app.report-cards.index') }}">Report cards</a>@endcan
        @can('academic.reports.view')<a class="nav {{ $on('reports') }}" href="{{ route('app.reports.index') }}">Reports</a>@endcan
        @can('academic.fees.view')
            <div class="group">Fees</div>
            <a class="nav {{ $r === 'app.fees.dashboard' ? 'on' : '' }}" href="{{ route('app.fees.dashboard') }}">Fees home</a>
            <a class="nav {{ $on('fees.students', 'fees.payments', 'fees.invoices') }}" href="{{ route('app.fees.students.index') }}">Collect fees</a>
            <a class="nav {{ $on('fees.approvals') }}" href="{{ route('app.fees.approvals') }}">Fee approvals
                @php($feePending = \Modules\Fees\Models\StudentFeeConcession::where('status', 'pending')->count() + \Modules\Fees\Models\FeeRefund::where('status', 'requested')->count())@if ($feePending)<span class="chip warning" style="margin-left:6px">{{ $feePending }}</span>@endif</a>
            <a class="nav {{ $on('fees.reports') }}" href="{{ route('app.fees.reports.outstanding') }}">Fee reports</a>
        @endcan
        @can('academic.hr.view')
            <div class="group">HR and payroll</div>
            <a class="nav {{ $r === 'app.hr.dashboard' ? 'on' : '' }}" href="{{ route('app.hr.dashboard') }}">HR home</a>
            <a class="nav {{ $on('hr.employees') }}" href="{{ route('app.hr.employees.index') }}">Employees</a>
            <a class="nav {{ $on('hr.attendance', 'hr.leave') }}" href="{{ route('app.hr.attendance') }}">Staff attendance and leave</a>
            <a class="nav {{ $on('hr.payroll', 'hr.payslips', 'hr.fnf') }}" href="{{ route('app.hr.payroll') }}">Payroll</a>
        @endcan
        @if (app(\Modules\Academic\Support\CurrentMember::class)->staff())<a class="nav {{ $on('me') }}" href="{{ route('app.me.index') }}">My leave and payslips</a>@endif
        @can('academic.accounts.view')
            <div class="group">Accounts</div>
            <a class="nav {{ $r === 'app.accounts.dashboard' ? 'on' : '' }}" href="{{ route('app.accounts.dashboard') }}">Accounts home</a>
            <a class="nav {{ $on('accounts.vouchers', 'accounts.approvals') }}" href="{{ route('app.accounts.vouchers.index') }}">Vouchers
                @php($pendingVouchers = \Modules\Accounting\Models\Voucher::where('status', 'pending')->count())@if ($pendingVouchers)<span class="chip warning" style="margin-left:6px">{{ $pendingVouchers }}</span>@endif</a>
            <a class="nav {{ $on('accounts.invoices', 'accounts.expenses') }}" href="{{ route('app.accounts.invoices.index') }}">Invoices and expenses</a>
            <a class="nav {{ $on('accounts.bank') }}" href="{{ route('app.accounts.bank') }}">Bank reconciliation</a>
            <a class="nav {{ $on('accounts.reports') }}" href="{{ route('app.accounts.reports.trial-balance') }}">Financial reports</a>
            <a class="nav {{ $on('accounts.chart', 'accounts.ledgers', 'accounts.opening', 'accounts.settings', 'accounts.budgets', 'accounts.assets') }}" href="{{ route('app.accounts.chart') }}">Chart and settings</a>
        @endcan
        @can('academic.ai.use')
            <div class="group">AI assistants</div>
            <a class="nav {{ $on('ai.index', 'ai.chat') }}" href="{{ route('app.ai.index') }}">Ask an assistant</a>
            @can('academic.ai.approve')<a class="nav {{ $on('ai.approvals') }}" href="{{ route('app.ai.approvals') }}">AI approvals
                @php($aiPending = \Modules\Ai\Models\AiActionRequest::where('status', 'pending')->count())@if ($aiPending)<span class="chip warning" style="margin-left:6px">{{ $aiPending }}</span>@endif</a>@endcan
            <a class="nav {{ $on('ai.documents') }}" href="{{ route('app.ai.documents') }}">Read documents</a>
            @can('academic.accounts.view')<a class="nav {{ $on('ai.anomalies') }}" href="{{ route('app.ai.anomalies') }}">Unusual entries</a>@endcan
            @can('academic.ai.configure')<a class="nav {{ $on('ai.settings', 'ai.prompts', 'ai.knowledge', 'ai.usage') }}" href="{{ route('app.ai.settings') }}">AI settings and usage</a>@endcan
        @endcan
        @can('academic.setup.manage')
            <div class="group">Settings</div>
            <a class="nav {{ $on('setup') }}" href="{{ route('app.setup.index') }}">Institution setup</a>
            <a class="nav {{ $on('staff') }}" href="{{ route('app.staff.index') }}">Staff</a>
        @endcan
        <div class="who">
            <b>{{ auth()->user()->name }}</b>{{ config('academic.roles.'.app(\Modules\Academic\Support\CurrentMember::class)->role()) }}
            <form method="post" action="{{ route('app.logout') }}">@csrf<button class="link" type="submit">Sign out</button></form>
        </div>
    </nav>
    <main class="main">
        @if (session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
        @if ($errors->any())
            <div class="alert err" role="alert"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif
        @yield('content')
    </main>
</div>
</body>
</html>
