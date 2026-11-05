@extends('academic::layout')
@section('title', 'Leave')
@section('content')
<div class="head"><h1>Leave</h1></div>
@include('hr::partials.tabs')
<nav class="toolbar">@foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $l)<a class="btn sm {{ $status === $k ? 'primary' : '' }}" href="{{ route('app.hr.leave', ['status' => $k]) }}">{{ $l }}</a>@endforeach</nav>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th class="num">Days</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody>
@forelse ($list as $l)<tr><td>{{ $l->staff->name }}</td><td>{{ $l->type->name }}</td><td>{{ $l->from_date->format('j M') }}{{ $l->to_date != $l->from_date ? ' – '.$l->to_date->format('j M') : '' }} {{ $l->half_day ? '(half day)' : '' }}</td><td class="num">{{ $l->days }}</td><td>{{ $l->reason }}</td><td>{{ $l->status }}</td>
<td>@can('academic.hr.manage')@if ($l->status === 'pending')<form method="post" action="{{ route('app.hr.leave.decide', $l) }}" class="inline">@csrf<button class="btn sm primary" name="decision" value="approve">Approve</button><button class="btn sm danger" name="decision" value="reject">Reject</button></form>
@elseif ($l->status === 'approved')<form method="post" action="{{ route('app.hr.leave.cancel', $l) }}" class="inline">@csrf<button class="btn sm">Cancel</button></form>@endif @endcan</td></tr>
@empty<tr><td colspan="7" class="empty">No leave applications.</td></tr>@endforelse</tbody></table></div>@include('academic::partials.pager', ['p' => $list])</section>
@can('academic.hr.manage')<details class="card"><summary>Record leave for an employee</summary><form method="post" action="{{ route('app.hr.leave.store') }}" class="form">@csrf
    <label class="field">Employee<select name="staff_member_id">@foreach ($staff as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
    <label class="field">Type<select name="leave_type_id">@foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></label>
    <label class="field">From<input type="date" name="from_date" required></label><label class="field">To<input type="date" name="to_date"></label>
    <label class="check"><input type="checkbox" name="half_day" value="1"> Half day</label><label class="field wide">Reason<input name="reason" required></label><div><button class="btn" type="submit">Record</button></div></form></details>@endcan
@endsection
