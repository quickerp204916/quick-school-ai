@extends('academic::layout')
@section('title', 'Mark attendance')
@section('content')
<div class="head"><div><h1>{{ $section->label() }}</h1><p class="muted" style="margin:4px 0 0">{{ \Illuminate\Support\Carbon::parse($date)->format('l, j F Y') }} · {{ $rows->count() }} students
    @if ($register)· last saved {{ $register->marked_at?->format('j M, g:i a') }}@endif</p></div>
    <a class="btn" href="{{ route('app.attendance.index', ['date' => $date]) }}">Back</a></div>
<form method="get" class="filters"><input type="hidden" name="section" value="{{ $section->id }}">
    <label class="field">Date<input type="date" name="date" value="{{ $date }}" max="{{ today()->format('Y-m-d') }}"></label>
    @if ($mode === 'period')<label class="field">Period<select name="period">@foreach ($periods as $p)<option value="{{ $p->id }}" @selected($p->id === $periodId)>{{ $p->name }} · {{ $p->timeRange() }}</option>@endforeach</select></label>@endif
    <button class="btn" type="submit">Open</button></form>
@if ($blocked)<div class="alert err" role="alert">{{ $blocked }}</div>@endif
@if ($mode === 'period' && $periods->isEmpty())<div class="alert err">No periods in this branch’s bell schedule yet.</div>@endif
<form class="card" method="post" action="{{ route('app.attendance.mark') }}">@csrf
    <input type="hidden" name="section_id" value="{{ $section->id }}"><input type="hidden" name="date" value="{{ $date }}">
    @if ($periodId)<input type="hidden" name="period_id" value="{{ $periodId }}">
        <div class="filters" style="margin-bottom:10px"><label class="field">{{ $t('subject') }} taught<select name="subject_id"><option value="">—</option>@foreach ($section->subjects as $ss)<option value="{{ $ss->subject_id }}">{{ $ss->subject->name }}</option>@endforeach</select></label></div>@endif
    <p class="small muted" style="margin-top:0">Everyone starts as present. Tap the others.</p>
    <div class="table-wrap"><table><thead><tr><th>Roll</th><th>Student</th><th>Status</th><th>Remark</th></tr></thead><tbody>
    @forelse ($rows as $r)
        @php($id = $r['student']->id)
        <tr><td>{{ $r['roll_no'] ?? '—' }}</td><td>{{ $r['student']->fullName() }}</td>
            <td><div class="att" role="radiogroup" aria-label="{{ $r['student']->fullName() }}">
                @foreach (config('academic.attendance.statuses') as $k => $l)
                    <label class="{{ $k }}" title="{{ $l }}"><input type="radio" name="entries[{{ $id }}][status]" value="{{ $k }}" @checked(old("entries.$id.status", $r['status']) === $k)><span>{{ ['present' => 'P', 'absent' => 'A', 'late' => 'L', 'half_day' => 'H', 'leave' => 'LV'][$k] }}</span></label>
                @endforeach</div></td>
            <td><input type="text" name="entries[{{ $id }}][remark]" value="{{ old("entries.$id.remark", $r['remark']) }}" maxlength="150" aria-label="Remark for {{ $r['student']->fullName() }}"></td></tr>
    @empty<tr><td colspan="4" class="empty">No students enrolled on this date.</td></tr>@endforelse
    </tbody></table></div>
    <p class="small muted">P present · A absent · L late · H half day · LV on leave. Parents of absent students are notified.</p>
    <button class="btn primary" type="submit" @disabled($blocked || $rows->isEmpty())>Save attendance</button>
</form>
@endsection
