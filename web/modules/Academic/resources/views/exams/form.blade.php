@extends('academic::layout')
@section('title', $exam->exists ? 'Edit '.$exam->name : 'New exam')
@section('content')
<div class="head"><h1>{{ $exam->exists ? 'Edit '.$exam->name : 'New exam' }}</h1><a class="btn" href="{{ $exam->exists ? route('app.exams.show', $exam) : route('app.exams.index') }}">Cancel</a></div>
<form class="card" method="post" action="{{ $exam->exists ? route('app.exams.update', $exam) : route('app.exams.store') }}">@csrf @if ($exam->exists) @method('put') @endif
    <div class="form">
        <label class="field">Session<select name="session_id" required>@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected(old('session_id', $exam->session_id) === $s->id)>{{ $s->name }}</option>@endforeach</select></label>
        <label class="field">{{ $t('term') }}<select name="term_id"><option value="">—</option>@foreach ($terms as $sid => $list)@foreach ($list as $tm)<option value="{{ $tm->id }}" @selected(old('term_id', $exam->term_id) === $tm->id)>{{ $sessions->firstWhere('id', $sid)?->name }} · {{ $tm->name }}</option>@endforeach @endforeach</select></label>
        <label class="field">Name<input type="text" name="name" value="{{ old('name', $exam->name) }}" required maxlength="80" placeholder="Half-yearly exam"></label>
        <label class="field">Type<select name="type">@foreach (config('academic.exam_types') as $k => $l)<option value="{{ $k }}" @selected(old('type', $exam->type) === $k)>{{ $l }}</option>@endforeach</select></label>
        <label class="field">Grading<select name="grading_scheme_id"><option value="">Default</option>@foreach ($schemes as $g)<option value="{{ $g->id }}" @selected(old('grading_scheme_id', $exam->grading_scheme_id) === $g->id)>{{ $g->name }}</option>@endforeach</select></label>
        <label class="field">Starts<input type="date" name="starts_on" value="{{ old('starts_on', $exam->starts_on?->format('Y-m-d')) }}" required></label>
        <label class="field">Ends<input type="date" name="ends_on" value="{{ old('ends_on', $exam->ends_on?->format('Y-m-d')) }}"></label>
    </div>
    <p><button class="btn primary" type="submit">Save</button></p>
</form>
@endsection
