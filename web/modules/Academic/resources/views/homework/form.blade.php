@extends('academic::layout')
@section('title', $hw->exists ? 'Edit homework' : 'Set homework')
@section('content')
<div class="head"><h1>{{ $hw->exists ? 'Edit homework' : 'Set homework' }}</h1><a class="btn" href="{{ $hw->exists ? route('app.homework.show', $hw) : route('app.homework.index') }}">Cancel</a></div>
<form class="card" method="post" action="{{ $hw->exists ? route('app.homework.update', $hw) : route('app.homework.store') }}">@csrf @if ($hw->exists) @method('put') @endif
    <div class="form">
        <label class="field">{{ $t('section') }} and {{ strtolower($t('subject')) }}<select name="section_subject" required>
            <option value="">Choose</option>
            @foreach ($sections as $s)<optgroup label="{{ $s->label() }}">@foreach ($s->subjects as $ss)<option value="{{ $s->id }}|{{ $ss->subject_id }}" @selected(old('section_subject', $hw->section_id.'|'.$hw->subject_id) === $s->id.'|'.$ss->subject_id)>{{ $s->label() }} · {{ $ss->subject->name }}</option>@endforeach</optgroup>@endforeach
        </select></label>
        
        <label class="field">Set on<input type="date" name="assigned_on" value="{{ old('assigned_on', $hw->assigned_on?->format('Y-m-d')) }}" required></label>
        <label class="field">Due on<input type="date" name="due_on" value="{{ old('due_on', $hw->due_on?->format('Y-m-d')) }}" required></label>
        <label class="field wide">Title<input type="text" name="title" value="{{ old('title', $hw->title) }}" required maxlength="150" placeholder="Exercise 4.2, questions 1 to 10"></label>
        <label class="field wide">Details<textarea name="details" rows="5">{{ old('details', $hw->details) }}</textarea></label>
    </div>
    <p><button class="btn primary" type="submit">{{ $hw->exists ? 'Save' : 'Set homework' }}</button></p>
</form>
@endsection
