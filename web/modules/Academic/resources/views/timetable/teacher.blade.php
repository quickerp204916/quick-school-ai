@extends('academic::layout')
@section('title', 'Teacher timetable')
@section('content')
<div class="head"><div><h1>Timetable · {{ $teacher?->name ?? 'Teacher' }}</h1><p class="muted" style="margin:4px 0 0">Session {{ $session->name }} @if ($grid)· {{ $grid['load'] }} periods a week @endif</p></div>
    <div class="toolbar no-print"><a class="btn" href="{{ route('app.timetable.section') }}">{{ $t('section') }} view</a><button class="btn" type="button" onclick="print()">Print</button></div></div>
<form method="get" class="filters no-print"><input type="hidden" name="session" value="{{ $session->id }}">
    <label class="field">Teacher<select name="staff" onchange="this.form.submit()">@foreach ($teachers as $tc)<option value="{{ $tc->id }}" @selected($teacher?->id === $tc->id)>{{ $tc->name }}</option>@endforeach</select></label>
    <noscript><button class="btn" type="submit">Show</button></noscript></form>
@if ($grid)
<section class="card"><div class="table-wrap"><table class="grid-tt"><thead><tr><th>Period</th>@foreach ($grid['days'] as $d)<th>{{ $d }}</th>@endforeach</tr></thead><tbody>
@foreach ($grid['periods'] as $p)
    <tr><th><span style="text-transform:none;letter-spacing:0;color:var(--ink)">{{ $p->name }}</span><div class="small muted" style="text-transform:none">{{ $p->timeRange() }}</div></th>
    @foreach ($grid['days'] as $n => $d)
        @if ($p->is_break)<td class="break">{{ $loop->first ? $p->name : '' }}</td>
        @else @php($c = $grid['cells'][$n.'|'.$p->sequence] ?? null)
            <td>@if ($c)<b>{{ $c->section->label() }}</b>{{ $c->subject->name }}{{ $c->room ? ' · '.$c->room : '' }}@else<span class="muted">Free</span>@endif</td>
        @endif
    @endforeach</tr>
@endforeach
</tbody></table></div></section>
@else<div class="card empty">No teaching staff yet.</div>@endif
@endsection
