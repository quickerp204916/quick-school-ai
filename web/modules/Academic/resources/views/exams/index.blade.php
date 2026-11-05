@extends('academic::layout')
@section('title', 'Exams')
@section('content')
<div class="head"><div><h1>Exams and marks</h1><p class="muted" style="margin:4px 0 0">Session {{ $session->name }}</p></div>
    @can('academic.exams.manage')<a class="btn primary" href="{{ route('app.exams.create', ['session' => $session->id]) }}">New exam</a>@endcan</div>
@include('academic::partials.session-filter')
@if ($mine->isNotEmpty())
<section class="card"><h2>Your papers to mark</h2><div class="table-wrap"><table><thead><tr><th>Exam</th><th>{{ $t('section') }}</th><th>{{ $t('subject') }}</th><th>Date</th><th></th></tr></thead><tbody>
    @foreach ($mine as $p)<tr><td>{{ $p->exam->name }}</td><td>{{ $p->section->label() }}</td><td>{{ $p->subject->name }}</td><td>{{ $p->exam_date?->format('j M') }}</td>
        <td><a class="btn sm primary" href="{{ route('app.papers.marks', $p) }}">Enter marks</a></td></tr>@endforeach</tbody></table></div></section>
@endif
<section class="card"><div class="table-wrap"><table><thead><tr><th>Exam</th><th>Type</th><th>{{ $t('term') }}</th><th>Dates</th><th class="num">Papers</th><th>Status</th><th></th></tr></thead><tbody>
@forelse ($exams as $e)
    <tr><td>@can('academic.exams.manage')<a href="{{ route('app.exams.show', $e) }}"><b>{{ $e->name }}</b></a>@else<b>{{ $e->name }}</b>@endcan</td><td>{{ config('academic.exam_types.'.$e->type) }}</td>
        <td>{{ $e->term?->name ?? '—' }}</td><td>{{ $e->starts_on->format('j M') }}{{ $e->ends_on ? ' – '.$e->ends_on->format('j M Y') : '' }}</td><td class="num">{{ $e->papers_count }}</td>
        <td><span class="chip {{ $e->status }}">{{ $e->status }}</span></td><td><a class="btn sm" href="{{ route('app.exams.analysis', $e) }}">Results</a></td></tr>
@empty<tr><td colspan="7" class="empty">No exams in this session.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
