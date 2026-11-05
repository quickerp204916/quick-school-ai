@extends('academic::layout')
@section('title', 'Monthly attendance')
@section('content')
<div class="head"><h1>Attendance for {{ date('F Y', strtotime($period.'-01')) }}</h1><a class="btn" href="{{ route('app.hr.attendance') }}">Daily register</a></div>
@include('hr::partials.tabs')
<form method="get" class="filters"><label class="field">Month<input type="month" name="month" value="{{ $period }}"></label><button class="btn" type="submit">Show</button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Employee</th><th class="num">Days in service</th><th class="num">Absent</th><th class="num">Half days</th><th class="num">Leave</th><th class="num">Late</th><th class="num">Loss of pay</th><th class="num">Overtime (h)</th></tr></thead><tbody>
@foreach ($rows as $r)<tr><td>{{ $r['staff']->name }}</td><td class="num">{{ $r['payable_days'] }}</td><td class="num">{{ $r['absent'] }}</td><td class="num">{{ $r['half_days'] }}</td><td class="num">{{ $r['leave'] }}</td><td class="num">{{ $r['late'] }}</td><td class="num">{{ $r['lop_days'] }}</td><td class="num">{{ round($r['overtime_minutes'] / 60, 1) }}</td></tr>@endforeach
</tbody></table></div></section>
@endsection
