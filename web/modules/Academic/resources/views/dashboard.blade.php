@extends('academic::layout')
@section('title', 'Dashboard')
@section('content')
<div class="head">
    <div><h1>Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</h1>
        <p class="muted" style="margin:4px 0 0">{{ today()->format('l, j F Y') }} · Session {{ $session?->name ?? 'not set' }}</p></div>
    <div class="toolbar">
        @can('academic.attendance.mark')<a class="btn primary" href="{{ route('app.attendance.index') }}">Mark attendance</a>@endcan
        @can('academic.admissions.manage')<a class="btn" href="{{ route('app.admissions.create') }}">New enquiry</a>@endcan
    </div>
</div>
@if (! $session)
    <div class="alert err">No current academic session. @can('academic.setup.manage')<a href="{{ route('app.setup.sessions') }}">Set one up</a>.@endcan</div>
@endif
<div class="grid">
    <div class="card kpi"><div class="label">Active students</div><div class="value">{{ number_format($students) }}</div>
        <div class="small muted">@if ($studentLimit !== null) Plan allows {{ number_format($studentLimit) }} @else No plan limit @endif</div>
        @if ($studentLimit)<div class="bar" style="margin-top:8px"><i style="width:{{ min(100, round($students * 100 / $studentLimit)) }}%"></i></div>@endif</div>
    <div class="card kpi"><div class="label">{{ $t('sections') }}</div><div class="value">{{ $sections }}</div><div class="small muted">in {{ $session?->name }}</div></div>
    <div class="card kpi"><div class="label">Attendance today</div><div class="value">{{ $marked }} / {{ $day->count() }}</div>
        <div class="small muted">{{ strtolower($t('sections')) }} marked · {{ $present }} present · {{ $absent }} absent</div></div>
    @if ($openEnquiries !== null)
        <div class="card kpi"><div class="label">Open admissions</div><div class="value">{{ $openEnquiries }}</div>
            <div class="small muted">@if ($followUps)<a href="{{ route('app.admissions.index', ['follow_up' => 1]) }}">{{ $followUps }} follow-ups due</a>@else No follow-ups due @endif</div></div>
    @endif
</div>
<div class="cols">
    <section class="card"><h2>Attendance not yet marked</h2>
        @php($pending = $day->where('marked', false))
        @if ($pending->isEmpty())<p class="muted">Every {{ strtolower($t('section')) }} is marked for today.</p>
        @else<div class="table-wrap"><table><thead><tr><th>{{ $t('section') }}</th><th class="num">Students</th><th></th></tr></thead><tbody>
            @foreach ($pending->take(12) as $row)
                <tr><td>{{ $row['section']->label() }}</td><td class="num">{{ $row['strength'] }}</td>
                    <td>@can('academic.attendance.mark')<a class="btn sm" href="{{ route('app.attendance.sheet', ['section' => $row['section']->id]) }}">Mark</a>@endcan</td></tr>
            @endforeach</tbody></table></div>
            @if ($pending->count() > 12)<p class="small muted">and {{ $pending->count() - 12 }} more.</p>@endif
        @endif
    </section>
    <section class="card"><h2>Homework due in the next 3 days</h2>
        @forelse ($dueHomework as $h)
            <p style="margin:0 0 8px"><a href="{{ route('app.homework.show', $h) }}">{{ $h->title }}</a><br>
                <span class="small muted">{{ $h->section->label() }} · {{ $h->subject->name }} · due {{ $h->due_on->format('D j M') }}</span></p>
        @empty<p class="muted">Nothing due.</p>@endforelse
    </section>
    <section class="card"><h2>Exams open for marks</h2>
        @forelse ($openExams as $e)
            <p style="margin:0 0 8px">@can('academic.exams.manage')<a href="{{ route('app.exams.show', $e) }}">{{ $e->name }}</a>@else {{ $e->name }} @endcan
                <span class="small muted">· from {{ $e->starts_on->format('j M') }}</span></p>
        @empty<p class="muted">No exams open.</p>@endforelse
        @can('academic.marks.enter')<a class="btn sm" href="{{ route('app.exams.index') }}">Enter marks</a>@endcan
    </section>
</div>
@endsection
