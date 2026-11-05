@extends('academic::layout')
@section('title', 'Sessions')
@section('content')
<div class="head"><div><h1>Academic sessions</h1><p class="muted" style="margin:4px 0 0">One session per academic year. Each has {{ strtolower($t('term')) }}s for exams and report cards.</p></div></div>
@include('academic::setup.nav')
@foreach ($sessions as $s)
<section class="card">
    <div class="head"><h2>{{ $s->name }} <span class="chip {{ $s->is_current ? 'current' : $s->status }}">{{ $s->is_current ? 'current' : $s->status }}</span></h2>
        <div class="toolbar">
            @if (! $s->is_current && $s->status !== 'closed')<form class="inline" method="post" action="{{ route('app.setup.sessions.current', $s) }}">@csrf<button class="btn sm primary" type="submit">Make current</button></form>@endif
            @if (! $s->is_current && $s->status !== 'closed')<form class="inline" method="post" action="{{ route('app.setup.sessions.close', $s) }}">@csrf<button class="btn sm danger" type="submit">Close session</button></form>@endif
        </div></div>
    <p class="small muted">{{ $s->starts_on->format('j M Y') }} to {{ $s->ends_on->format('j M Y') }}</p>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>{{ $t('term') }}</th><th>From</th><th>To</th><th></th></tr></thead><tbody>
        @forelse ($s->terms->sortBy('sequence') as $term)
            <tr><td>{{ $term->sequence }}</td><td>{{ $term->name }}</td><td>{{ $term->starts_on->format('j M Y') }}</td><td>{{ $term->ends_on->format('j M Y') }}</td>
                <td class="toolbar"><details><summary class="small">Edit</summary>
                    <form method="post" action="{{ route('app.setup.terms.update', $term) }}" class="filters" style="margin-top:6px">@csrf @method('put')
                        <label class="field">Name<input type="text" name="name" value="{{ $term->name }}" required></label>
                        <label class="field">Order<input type="number" name="sequence" value="{{ $term->sequence }}" min="1" max="12"></label>
                        <label class="field">From<input type="date" name="starts_on" value="{{ $term->starts_on->format('Y-m-d') }}"></label>
                        <label class="field">To<input type="date" name="ends_on" value="{{ $term->ends_on->format('Y-m-d') }}"></label>
                        <button class="btn sm" type="submit">Save</button></form></details>
                    <form class="inline" method="post" action="{{ route('app.setup.terms.destroy', $term) }}">@csrf @method('delete')<button class="btn sm danger" type="submit">Delete</button></form></td></tr>
        @empty<tr><td colspan="5" class="empty">No {{ strtolower($t('term')) }}s yet.</td></tr>@endforelse
    </tbody></table></div>
    @if ($s->status !== 'closed')
    <form method="post" action="{{ route('app.setup.terms.store', $s) }}" class="filters" style="margin-top:10px">@csrf
        <label class="field">New {{ strtolower($t('term')) }}<input type="text" name="name" required placeholder="Term 1"></label>
        <label class="field">Order<input type="number" name="sequence" value="{{ $s->terms->count() + 1 }}" min="1" max="12"></label>
        <label class="field">From<input type="date" name="starts_on" required></label>
        <label class="field">To<input type="date" name="ends_on" required></label>
        <button class="btn" type="submit">Add</button></form>
    <details style="margin-top:10px"><summary class="small">Edit session dates</summary>
        <form method="post" action="{{ route('app.setup.sessions.update', $s) }}" class="filters" style="margin-top:6px">@csrf @method('put')
            <label class="field">Name<input type="text" name="name" value="{{ $s->name }}" required></label>
            <label class="field">Starts<input type="date" name="starts_on" value="{{ $s->starts_on->format('Y-m-d') }}" required></label>
            <label class="field">Ends<input type="date" name="ends_on" value="{{ $s->ends_on->format('Y-m-d') }}" required></label>
            <button class="btn sm" type="submit">Save</button></form></details>
    @endif
</section>
@endforeach
<form class="card" method="post" action="{{ route('app.setup.sessions.store') }}">@csrf
    <h2>Add the next session</h2>
    <div class="filters">
        <label class="field">Name<input type="text" name="name" value="{{ old('name') }}" required placeholder="2027-28"></label>
        <label class="field">Starts<input type="date" name="starts_on" value="{{ old('starts_on') }}" required></label>
        <label class="field">Ends<input type="date" name="ends_on" value="{{ old('ends_on') }}" required></label>
        <button class="btn primary" type="submit">Add session</button>
    </div>
    <p class="small muted">New sessions start in planning. Create {{ strtolower($t('sections')) }} for it, promote students, then make it current.</p>
</form>
@endsection
