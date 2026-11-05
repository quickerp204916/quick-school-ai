@extends('academic::layout')
@section('title', 'Low attendance')
@section('content')
<div class="head"><div><h1>Low attendance</h1><p class="muted" style="margin:4px 0 0">{{ $rows->count() }} students below {{ $threshold }}% between {{ \Illuminate\Support\Carbon::parse($from)->format('j M') }} and {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}</p></div>
    <a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">Download CSV</a></div>
<form method="get" class="filters">
    <label class="field">From<input type="date" name="from" value="{{ $from }}"></label><label class="field">To<input type="date" name="to" value="{{ $to }}"></label>
    <label class="field">Below %<input type="number" name="below" value="{{ $threshold }}" min="1" max="100"></label><button class="btn" type="submit">Show</button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Student</th><th>{{ $t('section') }}</th><th class="num">Days marked</th><th class="num">Absent</th><th class="num">%</th><th>Guardian</th></tr></thead><tbody>
@forelse ($rows as $r)
    <tr><td><a href="{{ route('app.students.show', $r['student']) }}">{{ $r['student']->fullName() }}</a><div class="small muted">{{ $r['student']->admission_no }}</div></td>
        <td>{{ $r['student']->currentEnrollment?->section?->label() }}</td><td class="num">{{ $r['total'] }}</td><td class="num">{{ $r['counts']['absent'] }}</td>
        <td class="num"><b style="color:var(--bad)">{{ $r['percent'] }}</b></td><td>{{ $r['student']->primaryGuardian()?->name }}<div class="small muted">{{ $r['student']->primaryGuardian()?->phone }}</div></td></tr>
@empty<tr><td colspan="6" class="empty">Nobody is below {{ $threshold }}%.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
