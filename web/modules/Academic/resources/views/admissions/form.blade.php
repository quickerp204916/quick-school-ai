@extends('academic::layout')
@section('title', $app->exists ? 'Edit '.$app->number : 'New enquiry')
@section('content')
<div class="head"><h1>{{ $app->exists ? 'Edit '.$app->number : 'New enquiry or application' }}</h1>
    <a class="btn" href="{{ $app->exists ? route('app.admissions.show', $app) : route('app.admissions.index') }}">Cancel</a></div>
<form class="card" method="post" action="{{ $app->exists ? route('app.admissions.update', $app) : route('app.admissions.store') }}" enctype="multipart/form-data">@csrf @if ($app->exists) @method('put') @endif
    <h2>Applying for</h2>
    <div class="form">
        <label class="field">Session<select name="session_id" required>@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected(old('session_id', $app->session_id) === $s->id)>{{ $s->name }}</option>@endforeach</select></label>
        <label class="field">{{ $t('level') }}<select name="grade_level_id" required>@foreach ($levels as $l)<option value="{{ $l->id }}" @selected(old('grade_level_id', $app->grade_level_id) === $l->id)>{{ $l->program->name }} · {{ $l->name }}</option>@endforeach</select></label>
        <label class="field">Branch<select name="branch_id" required>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $app->branch_id) === $b->id)>{{ $b->name }}</option>@endforeach</select></label>
        <label class="field">How they heard of us<select name="source">@foreach (config('academic.admission.sources') as $k => $l)<option value="{{ $k }}" @selected(old('source', $app->source) === $k)>{{ $l }}</option>@endforeach</select></label>
    </div>
    <h2 style="margin-top:18px">Student</h2>
    <div class="form">
        <label class="field">First name<input type="text" name="first_name" value="{{ old('first_name', $app->first_name) }}" required maxlength="80"></label>
        <label class="field">Last name<input type="text" name="last_name" value="{{ old('last_name', $app->last_name) }}" maxlength="80"></label>
        <label class="field">Gender<select name="gender"><option value="">Not given</option>@foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $k => $l)<option value="{{ $k }}" @selected(old('gender', $app->gender) === $k)>{{ $l }}</option>@endforeach</select></label>
        <label class="field">Date of birth<input type="date" name="date_of_birth" value="{{ old('date_of_birth', $app->date_of_birth?->format('Y-m-d')) }}"></label>
        <label class="field">Previous school<input type="text" name="previous_school" value="{{ old('previous_school', $app->previous_school) }}"></label>
        <label class="field">Email<input type="email" name="email" value="{{ old('email', $app->email) }}"></label>
        <label class="field wide">Address<input type="text" name="address" value="{{ old('address', $app->address) }}"></label>
    </div>
    <h2 style="margin-top:18px">Parents or guardians</h2>
    @php($gs = old('guardians', $app->guardians ?: [[], []]))
    @foreach ([0, 1] as $i)
        <div class="form" style="margin-bottom:10px">
            <label class="field">{{ $i ? 'Second guardian (optional)' : 'Guardian name' }}<input type="text" name="guardians[{{ $i }}][name]" value="{{ $gs[$i]['name'] ?? '' }}" @if (! $i) required @endif></label>
            <label class="field">Relation<select name="guardians[{{ $i }}][relation]">@foreach (config('academic.relations') as $k => $l)<option value="{{ $k }}" @selected(($gs[$i]['relation'] ?? ($i ? 'mother' : 'father')) === $k)>{{ $l }}</option>@endforeach</select></label>
            <label class="field">Mobile<input type="text" name="guardians[{{ $i }}][phone]" value="{{ $gs[$i]['phone'] ?? '' }}" inputmode="tel" @if (! $i) required @endif></label>
            <label class="field">Email<input type="email" name="guardians[{{ $i }}][email]" value="{{ $gs[$i]['email'] ?? '' }}"></label>
            <label class="field">Occupation<input type="text" name="guardians[{{ $i }}][occupation]" value="{{ $gs[$i]['occupation'] ?? '' }}"></label>
        </div>
    @endforeach
    @unless ($app->exists)
    <h2 style="margin-top:18px">Documents and photo <span class="small muted" style="font-weight:400">optional now, can be added later</span></h2>
    <div class="form">
        @foreach (config('academic.admission.documents') as $type => $label)
            <label class="field">{{ $label }}<input type="file" name="uploads[{{ $type }}]" accept="{{ $type === 'photo' ? 'image/jpeg,image/png,image/webp' : 'application/pdf,image/jpeg,image/png,image/webp' }}" style="height:auto;padding:6px">
                @error('uploads.'.$type)<span class="small" style="color:var(--bad)">{{ $message }}</span>@enderror</label>
        @endforeach
    </div>
    <p class="small muted">Photo: JPG, PNG or WebP up to 2 MB. Documents: PDF or image up to 5 MB.</p>
    @endunless
    <h2 style="margin-top:18px">Follow-up</h2>
    <div class="form">
        <label class="field">Follow up on<input type="date" name="follow_up_on" value="{{ old('follow_up_on', $app->follow_up_on?->format('Y-m-d')) }}"></label>
        <label class="field">Entrance test on<input type="date" name="test_on" value="{{ old('test_on', $app->test_on?->format('Y-m-d')) }}"></label>
        <label class="field">Test score<input type="number" name="test_score" value="{{ old('test_score', $app->test_score) }}" step="0.01" min="0"></label>
        <label class="field wide">Notes<textarea name="notes" rows="3">{{ old('notes', $app->notes) }}</textarea></label>
    </div>
    <div class="toolbar" style="margin-top:14px">
        @if ($app->exists)<button class="btn primary" type="submit">Save</button>
        @else<button class="btn primary" type="submit" name="stage" value="applied">Save as application</button><button class="btn" type="submit" name="stage" value="enquiry">Save as enquiry</button>@endif
    </div>
</form>
@endsection
