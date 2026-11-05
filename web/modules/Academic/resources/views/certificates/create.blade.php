@extends('academic::layout')
@section('title', 'Issue certificate')
@section('content')
<div class="head"><div><h1>Issue a certificate</h1><p class="muted" style="margin:4px 0 0">{{ $s->fullName() }} · {{ $s->admission_no }} · <span class="chip {{ $s->status }}">{{ $s->status }}</span></p></div>
    <a class="btn" href="{{ route('app.students.show', $s) }}">Cancel</a></div>
@if ($s->status === 'active')<div class="alert ok" style="background:var(--royal-50);color:var(--royal-700)">A transfer certificate needs the student’s leaving to be recorded first, from the student’s page.</div>@endif
<form class="card" method="post" action="{{ route('app.certificates.store') }}">@csrf
    <input type="hidden" name="student_id" value="{{ $s->id }}">
    <div class="form">
        <label class="field">Certificate<select name="template_id" required>@foreach ($templates as $tpl)<option value="{{ $tpl->id }}" @selected(old('template_id', $template?->id) === $tpl->id)>{{ $tpl->name }}</option>@endforeach</select></label>
        <label class="field">Issue date<input type="date" name="issued_on" value="{{ old('issued_on', today()->format('Y-m-d')) }}" max="{{ today()->format('Y-m-d') }}"></label>
        <label class="field wide">Purpose <span class="hint">needed for bonafide certificates</span><input type="text" name="purpose" value="{{ old('purpose') }}" maxlength="200" placeholder="Passport application"></label>
    </div>
    <p class="small muted">The text is filled from the student’s record when issued and cannot be edited afterwards. Cancel and reissue to correct it.</p>
    <p><button class="btn primary" type="submit">Issue certificate</button></p>
</form>
@endsection
