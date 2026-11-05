@extends('academic::layout')
@section('title', 'Attendance')
@section('content')
@php($marked = $rows->where('marked', true))
<div class="head"><div><h1>Attendance</h1><p class="muted" style="margin:4px 0 0">{{ \Illuminate\Support\Carbon::parse($date)->format('l, j F Y') }} · {{ $marked->count() }} of {{ $rows->count() }} {{ strtolower($t('sections')) }} marked</p></div>
    <div class="toolbar"><a class="btn" href="{{ route('app.attendance.low') }}">Low attendance</a><a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">Download CSV</a></div></div>
<form method="get" class="filters"><input type="hidden" name="session" value="{{ $session->id }}">
    <label class="field">Date<input type="date" name="date" value="{{ $date }}" max="{{ today()->format('Y-m-d') }}"></label><button class="btn" type="submit">Show</button></form>
<div class="grid">
    <div class="card kpi"><div class="label">Present</div><div class="value">{{ $rows->sum(fn ($r) => $r['counts']['present'] + $r['counts']['late']) }}</div></div>
    <div class="card kpi"><div class="label">Absent</div><div class="value" style="color:var(--bad)">{{ $rows->sum(fn ($r) => $r['counts']['absent']) }}</div></div>
    <div class="card kpi"><div class="label">On leave</div><div class="value">{{ $rows->sum(fn ($r) => $r['counts']['leave']) }}</div></div>
    <div class="card kpi"><div class="label">Not marked</div><div class="value">{{ $rows->count() - $marked->count() }}</div></div>
</div>
<section class="card"><div class="table-wrap"><table><thead><tr><th>{{ $t('section') }}</th><th class="num">Strength</th><th>Marked</th><th class="num">Present</th><th class="num">Absent</th><th class="num">Late</th><th class="num">Leave</th><th class="num">%</th><th></th></tr></thead><tbody>
@forelse ($rows as $r)
    <tr><td><b>{{ $r['section']->label() }}</b></td><td class="num">{{ $r['strength'] }}</td>
        <td>@if ($r['marked'])<span class="chip present">{{ $r['registers'] > 1 ? $r['registers'].' periods' : 'yes' }}</span>@else<span class="chip warning">not yet</span>@endif</td>
        <td class="num">{{ $r['counts']['present'] }}</td><td class="num">{{ $r['counts']['absent'] }}</td><td class="num">{{ $r['counts']['late'] }}</td><td class="num">{{ $r['counts']['leave'] }}</td>
        <td class="num">{{ $r['percent'] ?? '—' }}</td>
        <td class="toolbar">@can('academic.attendance.mark')<a class="btn sm {{ $r['marked'] ? '' : 'primary' }}" href="{{ route('app.attendance.sheet', ['section' => $r['section']->id, 'date' => $date]) }}">{{ $r['marked'] ? 'Edit' : 'Mark' }}</a>@endcan
            <a class="btn sm" href="{{ route('app.attendance.register', ['section' => $r['section']->id, 'month' => substr($date, 0, 7)]) }}">Register</a></td></tr>
@empty<tr><td colspan="9" class="empty">No {{ strtolower($t('sections')) }} to show.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
