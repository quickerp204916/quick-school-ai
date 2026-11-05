<nav class="toolbar no-print" aria-label="HR">
    @foreach (['app.hr.dashboard' => 'Overview', 'app.hr.employees.index' => 'Employees', 'app.hr.attendance' => 'Attendance', 'app.hr.leave' => 'Leave', 'app.hr.payroll' => 'Payroll', 'app.hr.fnf' => 'Full and final', 'app.hr.settings' => 'Settings'] as $name => $label)
        @if ($name !== 'app.hr.settings' || auth()->user()->can('academic.payroll.configure'))
            <a class="btn sm {{ request()->routeIs($name) || request()->routeIs(str_replace(['.index', 'app.hr.payroll', 'app.hr.attendance'], ['.*', 'app.hr.payroll.*', 'app.hr.attendance.*'], $name)) ? 'primary' : '' }}" href="{{ route($name) }}">{{ $label }}</a>
        @endif
    @endforeach
</nav>
