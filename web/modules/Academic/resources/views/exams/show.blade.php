@extends('academic::layout')
@section('title', $exam->name)
@section('content')
@php($next = ['draft' => ['open' => 'Open marks entry'], 'open' => ['published' => 'Publish results', 'draft' => 'Back to draft'], 'published' => ['open' => 'Unpublish to correct marks']][$exam->status])
<div class="head"><div><h1>{{ $exam->name }} <span class="chip {{ $exam->status }}">{{ $exam->status }}</span></h1>
    <p class="muted" style="margin:4px 0 0">{{ config('academic.exam_types.'.$exam->type) }} · {{ $exam->session->name }}{{ $exam->term ? ' · '.$exam->term->name : '' }} · {{ $exam->gradingScheme?->name }}
        · {{ $exam->starts_on->format('j M') }}{{ $exam->ends_on ? ' – '.$exam->ends_on->format('j M Y') : '' }}</p></div>
    <div class="toolbar">@if ($exam->status !== 'published')<a class="btn" href="{{ route('app.exams.edit', $exam) }}">Edit</a>@endif
        <a class="btn" href="{{ route('app.exams.analysis', $exam) }}">Results</a>
        @foreach ($next as $s => $label)<form class="inline" method="post" action="{{ route('app.exams.status', $exam) }}">@csrf<input type="hidden" name="status" value="{{ $s }}"><button class="btn {{ $s === 'draft' ? '' : 'primary' }}" type="submit">{{ $label }}</button></form>@endforeach</div></div>
@forelse ($papers as $sectionId => $list)
<section class="card"><h2>{{ $list->first()->section->label() }} <span class="small muted">· {{ $strengths[$sectionId] ?? 0 }} students</span></h2>
    <div class="table-wrap"><table><thead><tr><th>{{ $t('subject') }}</th><th>Date</th><th class="num">Max</th><th class="num">Pass</th><th>Marks entered</th><th></th></tr></thead><tbody>
    @foreach ($list as $p)
        @php($n = $strengths[$sectionId] ?? 0)
        <tr><td>{{ $p->subject->name }}</td><td>{{ $p->exam_date?->format('D j M') }} {{ $p->starts_at ? substr($p->starts_at, 0, 5) : '' }}</td><td class="num">{{ $p->max_marks + 0 }}</td><td class="num">{{ $p->pass_marks + 0 }}</td>
            <td>{{ $p->entered }} / {{ $n }} <div class="bar" style="width:120px;margin-top:4px"><i style="width:{{ $n ? min(100, round($p->entered * 100 / $n)) : 0 }}%"></i></div></td>
            <td class="toolbar">@if ($exam->status === 'open')<a class="btn sm primary" href="{{ route('app.papers.marks', $p) }}">Marks</a>@endif
                @if ($exam->status !== 'published')<details><summary class="small">Edit</summary><form method="post" action="{{ route('app.papers.update', $p) }}" class="stack" style="margin-top:6px">@csrf @method('put')
                    <input type="number" name="max_marks" value="{{ $p->max_marks + 0 }}" step="0.5" min="1" aria-label="Maximum marks"><input type="number" name="pass_marks" value="{{ $p->pass_marks + 0 }}" step="0.5" min="0" aria-label="Pass marks">
                    <input type="date" name="exam_date" value="{{ $p->exam_date?->format('Y-m-d') }}" aria-label="Date"><input type="time" name="starts_at" value="{{ $p->starts_at ? substr($p->starts_at, 0, 5) : '' }}" aria-label="Start time">
                    <input type="number" name="duration_minutes" value="{{ $p->duration_minutes }}" min="5" max="600" aria-label="Duration in minutes">
                    <button class="btn sm" type="submit">Save</button></form></details>
                @if ($p->entered === 0)<form class="inline" method="post" action="{{ route('app.papers.destroy', $p) }}">@csrf @method('delete')<button class="btn sm danger" type="submit">Delete</button></form>@endif @endif</td></tr>
    @endforeach</tbody></table></div>
</section>
@empty<div class="card empty">No papers yet. Add them below.</div>@endforelse
@if ($exam->status !== 'published')
<form class="card" method="post" action="{{ route('app.exams.papers.store', $exam) }}">@csrf
    <h2>Add papers</h2>
    <p class="small muted" style="margin-top:-6px">One paper per {{ strtolower($t('subject')) }} taught in each chosen {{ strtolower($t('section')) }}. Leave {{ strtolower($t('subjects')) }} empty for all.</p>
    <div class="cols">
        <fieldset style="border:0;padding:0;margin:0"><legend class="small" style="font-weight:600">{{ $t('sections') }}</legend><div class="stack" style="max-height:240px;overflow:auto">
            @foreach ($sections as $s)<label class="check"><input type="checkbox" name="section_ids[]" value="{{ $s->id }}"> {{ $s->label() }}</label>@endforeach</div></fieldset>
        <fieldset style="border:0;padding:0;margin:0"><legend class="small" style="font-weight:600">{{ $t('subjects') }}</legend><div class="stack" style="max-height:240px;overflow:auto">
            @foreach ($subjects as $s)<label class="check"><input type="checkbox" name="subject_ids[]" value="{{ $s->id }}"> {{ $s->name }}</label>@endforeach</div></fieldset>
        <div class="stack">
            <label class="field">Maximum marks<input type="number" name="max_marks" value="{{ old('max_marks', 100) }}" step="0.5" min="1" required></label>
            <label class="field">Pass marks<input type="number" name="pass_marks" value="{{ old('pass_marks', 33) }}" step="0.5" min="0" required></label>
            <label class="field">Date<input type="date" name="exam_date"></label>
            <label class="field">Starts at<input type="time" name="starts_at"></label>
            <label class="field">Duration (minutes)<input type="number" name="duration_minutes" min="5" max="600"></label>
        </div>
    </div>
    <p><button class="btn primary" type="submit">Add papers</button></p>
</form>
@endif
@endsection
