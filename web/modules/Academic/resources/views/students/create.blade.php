@extends('academic::layout')
@section('title', 'Add student')
@section('content')
<div class="head"><div><h1>Add student</h1><p class="muted" style="margin:4px 0 0">Direct admission, or entering existing students. For new families, use <a href="{{ route('app.admissions.create') }}">Admissions</a>.</p></div>
    <a class="btn" href="{{ route('app.students.index') }}">Cancel</a></div>
<form class="card" method="post" action="{{ route('app.students.store') }}" enctype="multipart/form-data">@csrf
    <h2>Enrolment</h2>
    <div class="form">
        <label class="field">{{ $t('section') }}<select name="section_id" required>@foreach ($sections as $s)<option value="{{ $s->id }}" @selected(old('section_id', $presetSection) === $s->id)>{{ $s->label() }} · {{ $s->branch->name }} · {{ $s->strength }}{{ $s->capacity ? '/'.$s->capacity : '' }}</option>@endforeach</select></label>
        <label class="field">Roll number<input type="number" name="roll_no" value="{{ old('roll_no') }}" min="1" max="9999"></label>
        <label class="field">Admitted on<input type="date" name="admitted_on" value="{{ old('admitted_on', today()->format('Y-m-d')) }}" max="{{ today()->format('Y-m-d') }}"></label>
        <label class="field">Admission number <span class="hint">leave empty to number automatically</span><input type="text" name="admission_no" value="{{ old('admission_no') }}" maxlength="30"></label>
    </div>
    <h2 style="margin-top:18px">Student</h2>
    @include('academic::students.fields', ['s' => new \Modules\Academic\Models\Student()])
    <h2 style="margin-top:18px">Parent or guardian</h2>
    @include('academic::students.guardian-fields', ['name' => 'guardians[0]', 'g' => old('guardians.0', []), 'required' => true, 'default' => 'father'])
    <details style="margin-top:10px"><summary class="small">Add a second guardian</summary><div style="margin-top:10px">
        @include('academic::students.guardian-fields', ['name' => 'guardians[1]', 'g' => old('guardians.1', []), 'default' => 'mother'])</div></details>
    <h2 style="margin-top:18px">Photo and documents <span class="small muted" style="font-weight:400">optional</span></h2>
    <div class="form">
        @foreach (config('academic.admission.documents') as $type => $label)
            <label class="field">{{ $label }}<input type="file" name="uploads[{{ $type }}]" accept="{{ $type === 'photo' ? 'image/jpeg,image/png,image/webp' : 'application/pdf,image/jpeg,image/png,image/webp' }}" style="height:auto;padding:6px">
                @error('uploads.'.$type)<span class="small" style="color:var(--bad)">{{ $message }}</span>@enderror</label>
        @endforeach
    </div>
    <p class="small muted">If a guardian with the same mobile number already exists, the student is linked to them as a sibling.</p>
    <p><button class="btn primary" type="submit">Admit student</button></p>
</form>
@endsection
