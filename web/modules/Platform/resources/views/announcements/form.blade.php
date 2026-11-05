@extends('platform::layout')
@section('title', $announcement->exists ? 'Edit announcement' : 'New announcement')
@section('content')
<div class="head"><h1>{{ $announcement->exists ? 'Edit announcement' : 'New announcement' }}</h1><a class="btn" href="{{ route('platform.announcements.index') }}">Back</a></div>
<form class="card" method="post" action="{{ $announcement->exists ? route('platform.announcements.update', $announcement) : route('platform.announcements.store') }}">
    @csrf @if ($announcement->exists) @method('put') @endif
    <div class="form">
        <label class="field wide">Title<input type="text" name="title" value="{{ old('title', $announcement->title) }}" required maxlength="150"></label>
        <label class="field wide">Message<textarea name="body" required maxlength="2000">{{ old('body', $announcement->body) }}</textarea></label>
        <label class="field">Level<select name="level">@foreach (\Modules\Platform\Models\Announcement::LEVELS as $l)<option value="{{ $l }}" @selected(old('level', $announcement->level) === $l)>{{ ucfirst($l) }}</option>@endforeach</select></label>
        <label class="field">Audience<select name="audience"><option value="all" @selected(old('audience', $announcement->audience) === 'all')>All institutions</option><option value="plan" @selected(old('audience', $announcement->audience) === 'plan')>One plan</option></select></label>
        <label class="field">Plan<span class="hint">When the audience is one plan</span><select name="plan_id"><option value="">—</option>@foreach ($plans as $p)<option value="{{ $p->id }}" @selected(old('plan_id', $announcement->plan_id) === $p->id)>{{ $p->name }}</option>@endforeach</select></label>
        <label class="field">Show from<input type="datetime-local" name="starts_at" value="{{ old('starts_at', $announcement->starts_at?->format('Y-m-d\TH:i')) }}" required></label>
        <label class="field">Show until<span class="hint">Optional</span><input type="datetime-local" name="ends_at" value="{{ old('ends_at', $announcement->ends_at?->format('Y-m-d\TH:i')) }}"></label>
    </div>
    <div style="margin-top:18px"><button class="btn primary" type="submit">Save</button></div>
</form>
@endsection
