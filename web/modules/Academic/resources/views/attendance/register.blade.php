@extends('academic::layout')
@section('title', 'Attendance register')
@section('content')
<div class="head"><div><h1>Register · {{ $section->label() }}</h1><p class="muted" style="margin:4px 0 0">{{ \Illuminate\Support\Carbon::parse($month.'-01')->format('F Y') }} · {{ count($marked_days) }} days marked</p></div>
    <div class="toolbar"><a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">Download CSV</a><button class="btn no-print" type="button" onclick="print()">Print</button></div></div>
<form method="get" class="filters no-print"><input type="hidden" name="section" value="{{ $section->id }}">
    <label class="field">Month<input type="month" name="month" value="{{ $month }}" style="height:38px;border:1px solid var(--border-strong);border-radius:8px;padding:0 10px"></label><button class="btn" type="submit">Show</button></form>
<section class="card"><div class="table-wrap"><table class="reg"><thead><tr><th>Roll</th><th class="name">Name</th>
    @foreach ($days as $d)<th @class(['muted' => ! in_array($d, $marked_days, true)])>{{ (int) substr($d, 8) }}</th>@endforeach<th>%</th></tr></thead><tbody>
@forelse ($rows as $r)
    <tr><td>{{ $r['enrollment']->roll_no }}</td><td class="name">{{ $r['student']->fullName() }}</td>
        @foreach ($days as $d)@php($c = $r['cells'][$d] ?? '')<td class="{{ $c }}">{{ $c }}</td>@endforeach
        <td><b>{{ $r['summary']['percent'] ?? '—' }}</b></td></tr>
@empty<tr><td colspan="{{ count($days) + 3 }}" class="empty">No students.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
