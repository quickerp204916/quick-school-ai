@extends('academic::layout')
@section('title', 'Staff attendance')
@section('content')
<div class="head"><h1>Staff attendance</h1><a class="btn" href="{{ route('app.hr.attendance.month') }}">Monthly summary</a></div>
@include('hr::partials.tabs')
<form method="get" class="filters"><label class="field">Date<input type="date" name="date" value="{{ $date }}" max="{{ today()->format('Y-m-d') }}"></label><button class="btn" type="submit">Show</button></form>
<p class="small muted">Working days nobody marks count as present. Mark absences, half days and overtime; approved leave appears here automatically.</p>
<form method="post" action="{{ route('app.hr.attendance.mark') }}" class="card">@csrf<input type="hidden" name="date" value="{{ $date }}">
<div class="table-wrap"><table><thead><tr><th>Employee</th><th>Status</th><th>In</th><th>Out</th><th class="num">Overtime (min)</th><th>Remarks</th></tr></thead><tbody>
@foreach ($staff as $s) @php($m = $marks[$s->id] ?? null)
<tr><td>{{ $s->name }} <span class="small muted">{{ $s->employee_code }}</span></td>
<td><select name="rows[{{ $s->id }}][status]" aria-label="Status of {{ $s->name }}" @disabled($m?->source === 'leave')><option value="">{{ $m ? '' : 'Present (not marked)' }}</option>@foreach (\Modules\Hr\Models\Attendance::STATUSES as $k => $l)<option value="{{ $k }}" @selected($m?->status === $k)>{{ $l }}</option>@endforeach</select>@if ($m?->source === 'leave')<div class="small muted">{{ $m->remarks }} (approved leave)</div>@endif</td>
<td><input type="time" name="rows[{{ $s->id }}][check_in]" value="{{ $m?->check_in ? substr($m->check_in, 0, 5) : '' }}" aria-label="In"></td><td><input type="time" name="rows[{{ $s->id }}][check_out]" value="{{ $m?->check_out ? substr($m->check_out, 0, 5) : '' }}" aria-label="Out"></td>
<td><input class="num" type="number" min="0" name="rows[{{ $s->id }}][overtime_minutes]" value="{{ $m?->overtime_minutes ?: '' }}" aria-label="Overtime" style="width:90px"></td><td><input name="rows[{{ $s->id }}][remarks]" value="{{ $m?->remarks }}" aria-label="Remarks">@if ($m?->late_minutes)<span class="small muted">late {{ $m->late_minutes }} min</span>@endif</td></tr>
@endforeach</tbody></table></div>
@can('academic.hr.manage')<div class="toolbar" style="margin-top:12px"><button class="btn primary" type="submit">Save attendance</button></div>@endcan</form>
@can('academic.hr.manage')<details class="card"><summary>Import biometric punches</summary><form method="post" enctype="multipart/form-data" action="{{ route('app.hr.attendance.import') }}" class="form">@csrf
    <label class="field wide">CSV: employee_code,date,check_in,check_out<textarea name="csv" placeholder="E1001,2026-11-03,07:58,15:40"></textarea></label><label class="field">or a file<input type="file" name="file" accept=".csv,text/csv"></label><div><button class="btn" type="submit">Import</button></div></form></details>@endcan
@endsection
