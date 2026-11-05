@extends('academic::layout')
@section('title', 'Report cards')
@section('content')
<div class="head"><div><h1>Report cards</h1><p class="muted" style="margin:4px 0 0">Combine one or more published exams with weights, check the results, then publish to parents.</p></div></div>
@include('academic::partials.session-filter')
<form method="get" class="card">
    <input type="hidden" name="session" value="{{ $session->id }}">
    <div class="filters">
        <label class="field">{{ $t('section') }}<select name="section" required><option value="">Choose</option>@foreach ($sections as $s)<option value="{{ $s->id }}" @selected($section?->id === $s->id)>{{ $s->label() }}</option>@endforeach</select></label>
    </div>
    <h2 style="margin-top:14px">Exams and weights</h2>
    <div class="table-wrap"><table><thead><tr><th>Exam</th><th>Status</th><th class="num">Weight %</th></tr></thead><tbody>
    @foreach ($exams as $e)<tr><td>{{ $e->name }}</td><td><span class="chip {{ $e->status }}">{{ $e->status }}</span></td>
        <td class="num"><input type="number" name="exams[{{ $e->id }}]" value="{{ $weights[$e->id] ?? request('exams.'.$e->id) }}" min="0" max="100" step="1" style="width:90px" aria-label="Weight for {{ $e->name }}"></td></tr>@endforeach
    </tbody></table></div>
    <p class="small muted">Example: Half-yearly 40, Annual 60. Only published exams can be used when publishing.</p>
    <button class="btn" type="submit">Preview results</button>
</form>
@if ($section && $published->isNotEmpty())
<section class="card"><h2>Already published for {{ $section->label() }}</h2>
    @foreach ($published as $p)<p style="margin:0 0 6px"><a href="{{ route('app.report-cards.list', ['section' => $section->id, 'title' => $p->title]) }}">{{ $p->title }}</a> · {{ $p->n }} cards · {{ \Illuminate\Support\Carbon::parse($p->at)->format('j M Y') }}</p>@endforeach
</section>
@endif
@if ($preview)
<form class="card" method="post" action="{{ route('app.report-cards.publish', $section) }}">@csrf
    @foreach ($weights as $id => $w)<input type="hidden" name="exams[{{ $id }}]" value="{{ $w }}">@endforeach
    <h2>Preview · {{ $section->label() }}</h2>
    @php($incomplete = $preview->sum('incomplete'))
    @if ($incomplete)<div class="alert err">{{ $incomplete }} marks are missing. They count as zero until entered or marked absent.</div>@endif
    <div class="table-wrap"><table><thead><tr><th>Rank</th><th>Student</th>@foreach ($preview->first()['subjects'] ?? [] as $sub)<th class="num">{{ $sub['code'] }}</th>@endforeach<th class="num">%</th><th>Grade</th><th>Result</th><th class="num">Attendance</th><th>Remark</th></tr></thead><tbody>
    @foreach ($preview as $c)
        <tr><td>{{ $c['rank'] ?? '—' }}</td><td>{{ $c['student']->fullName() }}</td>
            @foreach ($c['subjects'] as $sub)<td class="num" @if ($sub['passed'] === false) style="color:var(--bad);font-weight:600" @endif>{{ $sub['type'] === 'co_scholastic' ? $sub['grade'] : $sub['percent'] }}</td>@endforeach
            <td class="num"><b>{{ $c['percentage'] }}</b>{{ $c['gpa'] !== null ? ' · '.$c['gpa'] : '' }}</td><td>{{ $c['grade'] }}</td><td><span class="chip {{ $c['result'] }}">{{ $c['result'] }}</span></td>
            <td class="num">{{ $c['attendance_percent'] !== null ? $c['attendance_percent'].'%' : '—' }}</td>
            <td><input type="text" name="remarks[{{ $c['student']->id }}]" maxlength="500" placeholder="Class teacher’s remark" aria-label="Remark for {{ $c['student']->fullName() }}"></td></tr>
    @endforeach</tbody></table></div>
    <div class="filters" style="margin-top:12px"><label class="field grow">Report card title<input type="text" name="title" required maxlength="80" value="Annual report {{ $session->name }}"></label>
        <button class="btn primary" type="submit">Publish {{ $preview->count() }} report cards</button></div>
    <p class="small muted">Publishing again with the same title replaces those cards.</p>
</form>
@endif
@endsection
