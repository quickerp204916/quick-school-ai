@extends('academic::layout')
@section('title', 'HR and payroll')
@section('content')
<div class="head"><h1>HR and payroll</h1>@can('academic.hr.manage')<a class="btn primary" href="{{ route('app.hr.employees.create') }}">Add employee</a>@endcan</div>
@include('hr::partials.tabs')
<div class="grid">
    <div class="card kpi"><div class="label">Employees</div><div class="value">{{ $headcount }}</div></div>
    <div class="card kpi"><div class="label">On leave today</div><div class="value">{{ $onLeave }}</div></div>
    <div class="card kpi"><div class="label">Absent today</div><div class="value">{{ $absent }}</div></div>
    <div class="card kpi"><div class="label">Last payroll</div><div class="value">{{ $lastRun ? $lastRun->label() : '—' }}</div>@if ($lastRun)<div class="small muted">{{ $lastRun->status }} · net {{ $money($lastRun->net) }}</div>@endif</div>
</div>
@if ($noSalary)<p class="alert err">{{ $noSalary }} active employee(s) have no salary structure and will be left out of payroll.</p>@endif
<div class="cols">
<section class="card"><h2>Leave waiting for approval</h2><div class="table-wrap"><table><tbody>
@forelse ($pendingLeave as $l)<tr><td>{{ $l->staff->name }}</td><td>{{ $l->type->name }}</td><td>{{ $l->from_date->format('j M') }}{{ $l->to_date != $l->from_date ? ' – '.$l->to_date->format('j M') : '' }}</td><td>{{ $l->days }} day(s)</td><td><a href="{{ route('app.hr.leave') }}">Review</a></td></tr>
@empty<tr><td class="empty">Nothing waiting.</td></tr>@endforelse</tbody></table></div></section>
<section class="card"><h2>Headcount by department</h2>@foreach ($byDept as $d => $n)<div class="kv"><span>{{ $d }}</span><span class="num">{{ $n }}</span></div>@endforeach
@if ($expiring->isNotEmpty())<h2 style="margin-top:14px">Documents expiring soon</h2>@foreach ($expiring as $d)<p class="small">{{ $d->staff->name }} · {{ $d->title }} · {{ $d->expires_on->format('j M Y') }}</p>@endforeach @endif</section>
</div>
@endsection
