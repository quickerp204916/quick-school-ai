@extends('academic::layout')
@section('title', 'Employees')
@section('content')
<div class="head"><h1>Employees</h1>@can('academic.hr.manage')<a class="btn primary" href="{{ route('app.hr.employees.create') }}">Add employee</a>@endcan</div>
@include('hr::partials.tabs')
<form method="get" class="filters"><label class="field">Search<input name="q" value="{{ request('q') }}" placeholder="Name or code"></label>
    <label class="field">Status<select name="status"><option value="active">Active</option><option value="inactive" @selected(request('status') === 'inactive')>Left</option><option value="all" @selected(request('status') === 'all')>All</option></select></label><button class="btn" type="submit">Show</button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Department</th><th>Designation</th><th>Joined</th>@can('academic.payroll.process')<th class="num">Monthly gross</th>@endcan</tr></thead><tbody>
@forelse ($list as $s)<tr><td>{{ $s->employee_code }}</td><td><a href="{{ route('app.hr.employees.show', $s) }}">{{ $s->name }}</a></td><td>{{ $s->profile?->department?->name ?? $s->department }}</td><td>{{ $s->profile?->designation?->name ?? $s->designation }}</td>
<td>{{ $s->joined_on?->format('j M Y') }}</td>@can('academic.payroll.process')<td class="num">{{ isset($gross[$s->id]) ? $money($gross[$s->id]) : 'not set' }}</td>@endcan</tr>
@empty<tr><td colspan="6" class="empty">No employees.</td></tr>@endforelse</tbody></table></div>@include('academic::partials.pager', ['p' => $list])</section>
@endsection
