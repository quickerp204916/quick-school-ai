{{-- Session picker for list pages. Needs $sessions and $session. Keeps other query values. --}}
@php($sessions ??= \Modules\Institution\Models\AcademicSession::orderByDesc('starts_on')->get())
<form method="get" class="filters no-print">
    @foreach (request()->except(['session', 'page']) as $k => $v)@if (is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
    <label class="field">Session<select name="session" onchange="this.form.submit()">
        @foreach ($sessions as $s)<option value="{{ $s->id }}" @selected($s->id === $session->id)>{{ $s->name }}{{ $s->is_current ? ' (current)' : '' }}</option>@endforeach
    </select></label>
    <noscript><button class="btn" type="submit">Show</button></noscript>
</form>
