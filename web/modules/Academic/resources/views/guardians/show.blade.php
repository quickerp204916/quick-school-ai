@extends('academic::layout')
@section('title', $g->name)
@section('content')
<div class="head"><h1>{{ $g->name }}</h1>
    @can('academic.students.manage')@if ($g->email && ! $g->user_id)<form method="post" action="{{ route('app.guardians.invite', $g) }}">@csrf<button class="btn" type="submit">Invite to parent app</button></form>@endif @endcan</div>
<div class="cols">
<section class="card"><h2>Contact</h2><dl class="kv"><dt>Mobile</dt><dd>{{ $g->phone }}</dd><dt>Other phone</dt><dd>{{ $g->alt_phone ?: '—' }}</dd><dt>Email</dt><dd>{{ $g->email ?: '—' }}</dd>
    <dt>Occupation</dt><dd>{{ $g->occupation ?: '—' }}</dd><dt>Address</dt><dd>{{ $g->address ?: '—' }}</dd><dt>Parent app</dt><dd>{{ $g->user_id ? 'Invited' : 'Not invited' }}</dd></dl>
    @can('academic.students.manage')<details style="margin-top:10px"><summary class="small">Edit contact</summary>
        <form method="post" action="{{ route('app.guardians.update', $g) }}" class="form" style="margin-top:8px">@csrf @method('put')
            <label class="field">Name<input type="text" name="name" value="{{ $g->name }}" required></label>
            <label class="field">Mobile<input type="text" name="phone" value="{{ $g->phone }}" required></label>
            <label class="field">Other phone<input type="text" name="alt_phone" value="{{ $g->alt_phone }}"></label>
            <label class="field">Email<input type="email" name="email" value="{{ $g->email }}"></label>
            <label class="field">Occupation<input type="text" name="occupation" value="{{ $g->occupation }}"></label>
            <label class="field wide">Address<input type="text" name="address" value="{{ $g->address }}"></label>
            <div class="wide"><button class="btn" type="submit">Save</button></div></form></details>@endcan
</section>
<section class="card"><h2>Children</h2>
    @foreach ($g->students as $st)<p style="margin:0 0 8px"><a href="{{ route('app.students.show', $st) }}"><b>{{ $st->fullName() }}</b></a> · {{ config('academic.relations.'.$st->pivot->relation) }}
        <br><span class="small muted">{{ $st->admission_no }} · {{ $st->currentEnrollment?->section?->label() ?? $st->status }}</span></p>@endforeach
</section>
</div>
@endsection
