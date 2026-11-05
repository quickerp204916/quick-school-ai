@extends('academic::layout')
@section('title', 'Timetable')
@section('content')
<div class="head"><div><h1>Timetable{{ $section ? ' · '.$section->label() : '' }}</h1><p class="muted" style="margin:4px 0 0">Session {{ $session->name }}</p></div>
    <div class="toolbar no-print"><a class="btn" href="{{ route('app.timetable.teacher') }}">Teacher view</a><button class="btn" type="button" onclick="print()">Print</button></div></div>
<form method="get" class="filters no-print"><input type="hidden" name="session" value="{{ $session->id }}">
    <label class="field">{{ $t('section') }}<select name="section" onchange="this.form.submit()">@foreach ($sections as $s)<option value="{{ $s->id }}" @selected($section?->id === $s->id)>{{ $s->label() }}</option>@endforeach</select></label>
    <noscript><button class="btn" type="submit">Show</button></noscript></form>
@if (! $section)<div class="card empty">No {{ strtolower($t('sections')) }} to show.</div>
@elseif ($grid['periods']->isEmpty())<div class="alert err">This branch has no bell schedule yet. @can('academic.setup.manage')<a href="{{ route('app.setup.calendar') }}">Add periods</a>.@endcan</div>
@else
<section class="card"><div class="table-wrap"><table class="grid-tt"><thead><tr><th>Period</th>@foreach ($grid['days'] as $n => $d)<th>{{ $d }}</th>@endforeach</tr></thead><tbody>
@foreach ($grid['periods'] as $p)
    <tr><th><span style="text-transform:none;letter-spacing:0;color:var(--ink)">{{ $p->name }}</span><div class="small muted" style="text-transform:none">{{ $p->timeRange() }}</div></th>
    @foreach ($grid['days'] as $n => $d)
        @if ($p->is_break)<td class="break">{{ $loop->first ? $p->name : '' }}</td>
        @else @php($c = $grid['cells'][$n.'|'.$p->id] ?? null)
            <td>@if ($c)<b>{{ $c->subject->name }}</b>{{ $c->teacher?->name }}{{ $c->room ? ' · '.$c->room : '' }}
                @if ($canEdit)<form method="post" action="{{ route('app.timetable.clear', $c) }}" class="no-print">@csrf @method('delete')<button class="btn sm" type="submit" style="height:24px;margin-top:4px" aria-label="Clear {{ $d }} {{ $p->name }}">Clear</button></form>@endif
            @else<span class="muted">—</span>@endif</td>
        @endif
    @endforeach</tr>
@endforeach
</tbody></table></div></section>
@if ($canEdit)
<form class="card no-print" method="post" action="{{ route('app.timetable.set', $section) }}">@csrf
    <h2>Set a slot</h2>
    <div class="filters">
        <label class="field">Day<select name="day_of_week">@foreach ($grid['days'] as $n => $d)<option value="{{ $n }}" @selected(old('day_of_week') == $n)>{{ $d }}</option>@endforeach</select></label>
        <label class="field">Period<select name="period_id">@foreach ($grid['periods']->where('is_break', false) as $p)<option value="{{ $p->id }}" @selected(old('period_id') === $p->id)>{{ $p->name }} · {{ $p->timeRange() }}</option>@endforeach</select></label>
        <label class="field">{{ $t('subject') }}<select name="subject_id">@foreach ($offered as $o)<option value="{{ $o->subject_id }}" @selected(old('subject_id') === $o->subject_id)>{{ $o->subject->name }}{{ $o->teacher ? ' · '.$o->teacher->name : '' }}</option>@endforeach</select></label>
        <label class="field">Teacher <span class="hint">default: the subject teacher</span><select name="teacher_id"><option value="">Subject teacher</option>@foreach ($teachers as $tc)<option value="{{ $tc->id }}" @selected(old('teacher_id') === $tc->id)>{{ $tc->name }}</option>@endforeach</select></label>
        <label class="field">Room<input type="text" name="room" value="{{ old('room', $section->room) }}" maxlength="40"></label>
        <button class="btn primary" type="submit">Save slot</button>
    </div>
    <p class="small muted">Clashes are refused: a teacher or room can only be in one place per period.</p>
</form>
@endif
@endif
@endsection
