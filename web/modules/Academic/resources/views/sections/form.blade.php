@extends('academic::layout')
@section('title', $section->exists ? 'Edit '.$section->label() : 'New '.strtolower($t('section')))
@section('content')
<div class="head"><h1>{{ $section->exists ? 'Edit '.$section->label() : 'New '.strtolower($t('section')) }}</h1>
    <a class="btn" href="{{ $section->exists ? route('app.sections.show', $section) : route('app.sections.index') }}">Cancel</a></div>
<form class="card" method="post" action="{{ $section->exists ? route('app.sections.update', $section) : route('app.sections.store') }}">@csrf @if ($section->exists) @method('put') @endif
    <div class="form">
        <label class="field">Session<select name="session_id" required>@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected(old('session_id', $section->session_id) === $s->id)>{{ $s->name }}</option>@endforeach</select></label>
        <label class="field">{{ $t('level') }}<select name="grade_level_id" required>@foreach ($levels as $l)<option value="{{ $l->id }}" @selected(old('grade_level_id', $section->grade_level_id) === $l->id)>{{ $l->program->name }} · {{ $l->name }}</option>@endforeach</select></label>
        <label class="field">Branch<select name="branch_id" required>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $section->branch_id) === $b->id)>{{ $b->name }}</option>@endforeach</select></label>
        <label class="field">{{ $t('section') }} name<input type="text" name="name" value="{{ old('name', $section->name) }}" required maxlength="40" placeholder="{{ $t('section') === 'Batch' ? 'Morning batch' : 'A' }}"></label>
        <label class="field">Class teacher<select name="class_teacher_id"><option value="">—</option>@foreach ($teachers as $tc)<option value="{{ $tc->id }}" @selected(old('class_teacher_id', $section->class_teacher_id) === $tc->id)>{{ $tc->name }}</option>@endforeach</select></label>
        <label class="field">Capacity<input type="number" name="capacity" value="{{ old('capacity', $section->capacity) }}" min="1" max="500"></label>
        <label class="field">Room<input type="text" name="room" value="{{ old('room', $section->room) }}" maxlength="40"></label>
        <label class="field">Starts on <span class="hint">batches</span><input type="date" name="starts_on" value="{{ old('starts_on', $section->starts_on?->format('Y-m-d')) }}"></label>
        <label class="field">Ends on<input type="date" name="ends_on" value="{{ old('ends_on', $section->ends_on?->format('Y-m-d')) }}"></label>
        <label class="field wide">Schedule note<input type="text" name="schedule_note" value="{{ old('schedule_note', $section->schedule_note) }}" placeholder="Mon, Wed, Fri · 7:00–9:00 am"></label>
        <input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $section->is_active))> Active</label>
    </div>
    <p><button class="btn primary" type="submit">Save</button></p>
</form>
@endsection
