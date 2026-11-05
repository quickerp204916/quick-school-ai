@extends('academic::layout')
@section('title', 'Homework')
@section('content')
<div class="head"><h1>Homework</h1><a class="btn primary" href="{{ route('app.homework.create', ['section' => request('section')]) }}">Set homework</a></div>
<form method="get" class="filters">
    <label class="field">{{ $t('section') }}<select name="section"><option value="">All</option>@foreach ($sections as $s)<option value="{{ $s->id }}" @selected(request('section') === $s->id)>{{ $s->label() }}</option>@endforeach</select></label>
    <label class="field">Show<select name="when"><option value="">All</option><option value="due" @selected(request('when') === 'due')>Due today or later</option></select></label>
    <button class="btn" type="submit">Filter</button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Homework</th><th>{{ $t('section') }}</th><th>{{ $t('subject') }}</th><th>Set</th><th>Due</th><th class="num">Done</th><th>Status</th></tr></thead><tbody>
@forelse ($list as $h)
    <tr><td><a href="{{ route('app.homework.show', $h) }}"><b>{{ $h->title }}</b></a><div class="small muted">{{ $h->teacher?->name }}</div></td><td>{{ $h->section->label() }}</td><td>{{ $h->subject->name }}</td>
        <td>{{ $h->assigned_on->format('j M') }}</td><td>{{ $h->due_on->format('D j M') }}</td><td class="num">{{ $h->done }}</td><td><span class="chip {{ $h->status }}">{{ $h->status }}</span></td></tr>
@empty<tr><td colspan="7" class="empty">No homework yet.</td></tr>@endforelse
</tbody></table></div>@include('academic::partials.pager', ['p' => $list])</section>
@endsection
