@extends('academic::layout')
@section('title', $t('sections'))
@section('content')
<div class="head"><div><h1>{{ $t('levels') }} and {{ strtolower($t('sections')) }}</h1><p class="muted" style="margin:4px 0 0">Session {{ $session->name }}</p></div>
    @can('academic.setup.manage')<a class="btn primary" href="{{ route('app.sections.create', ['session' => $session->id]) }}">New {{ strtolower($t('section')) }}</a>@endcan</div>
<div class="filters">
    @include('academic::partials.session-filter')
    <form method="get" class="filters">
        <input type="hidden" name="session" value="{{ $session->id }}">
        <label class="field">{{ $t('level') }}<select name="level"><option value="">All</option>@foreach ($levels as $l)<option value="{{ $l->id }}" @selected(request('level') === $l->id)>{{ $l->program->name }} · {{ $l->name }}</option>@endforeach</select></label>
        <label class="field">Branch<select name="branch"><option value="">All</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(request('branch') === $b->id)>{{ $b->name }}</option>@endforeach</select></label>
        <button class="btn" type="submit">Filter</button>
    </form>
</div>
<section class="card"><div class="table-wrap"><table><thead><tr><th>{{ $t('section') }}</th><th>{{ $t('program') }}</th><th>Branch</th><th>Class teacher</th><th class="num">Students</th><th class="num">Capacity</th><th>Room</th></tr></thead><tbody>
@forelse ($sections as $s)
    <tr><td><a href="{{ route('app.sections.show', $s) }}"><b>{{ $s->label() }}</b></a> @unless ($s->is_active)<span class="chip closed">inactive</span>@endunless
            @if ($s->schedule_note)<div class="small muted">{{ $s->schedule_note }}</div>@endif</td>
        <td>{{ $s->level->program->name }}</td><td>{{ $s->branch->name }}</td><td>{{ $s->classTeacher?->name ?? '—' }}</td>
        <td class="num">{{ $s->strength }}</td><td class="num">{{ $s->capacity ?? '—' }}</td><td>{{ $s->room }}</td></tr>
@empty<tr><td colspan="7" class="empty">No {{ strtolower($t('sections')) }} in this session yet.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
