@extends('academic::layout')
@section('title', $app->number)
@section('content')
@php($next = config('academic.admission.transitions.'.$app->stage, []))
<div class="head"><div><h1>{{ $app->fullName() }} <span class="chip {{ $app->stage }}">{{ str_replace('_', ' ', $app->stage) }}</span></h1>
    <p class="muted" style="margin:4px 0 0">{{ $app->number }} · {{ $app->level->name }}, {{ $app->branch->name }} · {{ $app->session->name }}</p></div>
    <div class="toolbar">@if ($app->isOpen())<a class="btn" href="{{ route('app.admissions.edit', $app) }}">Edit</a>@endif
        @if ($app->student)<a class="btn primary" href="{{ route('app.students.show', $app->student) }}">Open student record</a>@endif</div></div>
<div class="cols">
<section class="card"><h2>Details</h2><dl class="kv">
    <dt>Gender</dt><dd>{{ ucfirst($app->gender ?? '—') }}</dd><dt>Date of birth</dt><dd>{{ $app->date_of_birth?->format('j M Y') ?? '—' }}</dd>
    <dt>Previous school</dt><dd>{{ $app->previous_school ?: '—' }}</dd><dt>Source</dt><dd>{{ config('academic.admission.sources.'.$app->source) }}</dd>
    @foreach ($app->guardians ?? [] as $g)<dt>{{ config('academic.relations.'.($g['relation'] ?? 'guardian')) }}</dt><dd>{{ $g['name'] ?? '' }} · {{ $g['phone'] ?? '' }} {{ ! empty($g['email']) ? '· '.$g['email'] : '' }}</dd>@endforeach
    <dt>Follow up</dt><dd>{{ $app->follow_up_on?->format('j M Y') ?? '—' }}</dd>
    <dt>Entrance test</dt><dd>{{ $app->test_on?->format('j M Y') ?? '—' }} {{ $app->test_score !== null ? '· score '.$app->test_score : '' }}</dd>
    @if ($app->rejection_reason)<dt>Reason</dt><dd>{{ $app->rejection_reason }}</dd>@endif
    <dt>Notes</dt><dd>{{ $app->notes ?: '—' }}</dd></dl></section>
<section class="card" style="flex:0 1 220px"><h2>Photo</h2>
    @if ($uploads->has('photo'))<img src="{{ route('app.documents.show', $uploads['photo']) }}" alt="Photo of {{ $app->fullName() }}" style="width:150px;height:190px;object-fit:cover;border-radius:10px;border:1px solid var(--border)">
    @else<div style="width:150px;height:190px;border-radius:10px;border:1px dashed var(--border-strong);display:grid;place-items:center;text-align:center" class="small muted">No photo yet</div>@endif
</section>
</div>
<section class="card"><h2>Supporting documents and photo</h2>
    <p class="small muted" style="margin-top:-6px">Upload scans or phone photos. Uploading marks the document as received. After admission the files move to the student’s record.</p>
    @include('academic::partials.documents', ['files' => $uploads, 'uploadUrl' => route('app.admissions.uploads.store', $app), 'canChange' => true,
        'required' => $required, 'received' => $app->documents ?? [], 'open' => $app->isOpen()])
    @if ($app->isOpen())
    <details style="margin-top:12px"><summary>Received as paper copies instead</summary>
        <form method="post" action="{{ route('app.admissions.documents', $app) }}" class="stack" style="margin-top:8px">@csrf
            @foreach (config('academic.admission.documents') as $k => $l)
                <label class="check"><input type="checkbox" name="documents[{{ $k }}]" value="1" @checked($app->documents[$k] ?? false) @disabled($uploads->has($k))> {{ $l }}{{ $uploads->has($k) ? ' (uploaded)' : '' }}</label>
            @endforeach
            <div><button class="btn sm" type="submit">Save checklist</button></div>
        </form></details>
    @endif
</section>
@if ($next)
<section class="card"><h2>Next step</h2>
    <form method="post" action="{{ route('app.admissions.stage', $app) }}" class="filters">@csrf
        <label class="field">Move to<select name="stage">@foreach ($next as $s)<option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach</select></label>
        <label class="field grow">Reason <span class="hint">needed to reject or withdraw</span><input type="text" name="reason" maxlength="300"></label>
        <button class="btn" type="submit">Move</button></form>
</section>
@endif
@if ($app->stage === 'offered')
<form class="card" method="post" action="{{ route('app.admissions.admit', $app) }}">@csrf
    <h2>Admit</h2>
    <p class="small muted" style="margin-top:-6px">Creates the student record with an admission number, links the guardians and enrols the student.</p>
    <div class="form">
        <label class="field">{{ $t('section') }}<select name="section_id" required>@foreach ($sections as $s)<option value="{{ $s->id }}">{{ $s->label() }} · {{ $s->strength }}{{ $s->capacity ? '/'.$s->capacity : '' }}</option>@endforeach</select></label>
        <label class="field">Roll number<input type="number" name="roll_no" min="1" max="9999"></label>
        <label class="field">Admitted on<input type="date" name="admitted_on" value="{{ today()->format('Y-m-d') }}" max="{{ today()->format('Y-m-d') }}"></label>
        @unless ($app->gender)<label class="field">Gender<select name="gender" required><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select></label>@endunless
        @unless ($app->date_of_birth)<label class="field">Date of birth<input type="date" name="date_of_birth" required></label>@endunless
    </div>
    @if ($sections->isEmpty())<p class="alert err">No active {{ strtolower($t('section')) }} of {{ $app->level->name }} in this branch and session. Create one first.</p>@endif
    <p><button class="btn primary" type="submit" @disabled($sections->isEmpty())>Admit student</button></p>
</form>
@endif
@endsection
