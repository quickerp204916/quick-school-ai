@extends('academic::layout')
@section('title', 'Certificate templates')
@section('content')
<div class="head"><h1>Certificate templates</h1></div>
@include('academic::setup.nav')
<div class="cols">
<div class="stack" style="flex:2 1 520px">
@foreach ([...$templates, null] as $tpl)
<form class="card" method="post" action="{{ $tpl ? route('app.setup.templates.update', $tpl) : route('app.setup.templates.store') }}">@csrf @if ($tpl) @method('put') @endif
    <h2>{{ $tpl?->name ?? 'New template' }}</h2>
    <div class="form">
        <label class="field">Type<select name="type">@foreach (config('academic.certificate_types') as $k => $c)<option value="{{ $k }}" @selected($tpl?->type === $k)>{{ $c['label'] }}</option>@endforeach</select></label>
        <label class="field">Title on certificate<input type="text" name="name" value="{{ $tpl?->name }}" required></label>
        <label class="field wide">Text<textarea name="body" rows="7" required>{{ $tpl?->body }}</textarea></label>
        <input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" @checked($tpl?->is_active ?? true)> In use</label>
    </div>
    <p><button class="btn {{ $tpl ? '' : 'primary' }}" type="submit">{{ $tpl ? 'Save' : 'Add template' }}</button></p>
</form>
@endforeach
</div>
<section class="card" style="flex:1 1 260px;align-self:flex-start"><h2>Placeholders</h2>
    <p class="small muted">Type these in the text. They are filled from the student’s record when issuing.</p>
    <dl class="kv small">@foreach ($placeholders as $k => $l)<dt><code>{{ '{'.$k.'}' }}</code></dt><dd>{{ $l }}</dd>@endforeach</dl>
</section>
</div>
@endsection
