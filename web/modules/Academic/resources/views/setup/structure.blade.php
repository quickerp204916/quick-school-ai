@extends('academic::layout')
@section('title', 'Academic structure')
@section('content')
<div class="head"><div><h1>Academic structure</h1>
    <p class="muted" style="margin:4px 0 0">{{ $t('program') }} → {{ $t('level') }} → {{ $t('section') }}. {{ $t('subjects') }} set on a {{ strtolower($t('level')) }} are copied to each new {{ strtolower($t('section')) }}.</p></div></div>
@include('academic::setup.nav')

@foreach ($programs as $p)
<section class="card">
    <div class="head"><h2>{{ $p->name }} <span class="small muted">{{ $p->code }} · {{ $p->gradingScheme?->name ?? 'default grading' }}</span> @unless ($p->is_active)<span class="chip closed">inactive</span>@endunless</h2>
        <details><summary class="small">Edit {{ strtolower($t('program')) }}</summary>
            <form method="post" action="{{ route('app.setup.programs.update', $p) }}" class="filters" style="margin-top:6px">@csrf @method('put')
                <label class="field">Code<input type="text" name="code" value="{{ $p->code }}" required></label>
                <label class="field">Name<input type="text" name="name" value="{{ $p->name }}" required></label>
                <label class="field">Grading<select name="grading_scheme_id"><option value="">Default</option>@foreach ($schemes as $s)<option value="{{ $s->id }}" @selected($p->grading_scheme_id === $s->id)>{{ $s->name }}</option>@endforeach</select></label>
                <label class="field">Order<input type="number" name="sort_order" value="{{ $p->sort_order }}"></label>
                <input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" @checked($p->is_active)> Active</label>
                <button class="btn sm" type="submit">Save</button></form></details></div>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>{{ $t('level') }}</th><th>{{ $t('subjects') }}</th><th></th></tr></thead><tbody>
    @foreach ($p->levels->sortBy('sequence') as $l)
        <tr><td>{{ $l->sequence }}</td><td><b>{{ $l->name }}</b> <span class="small muted">{{ $l->code }}</span> @unless ($l->is_active)<span class="chip closed">inactive</span>@endunless</td>
            <td class="small">@forelse ($l->subjects as $s){{ $s->name }}{{ $s->pivot->is_optional ? ' (optional)' : '' }}@if (! $loop->last), @endif @empty<span class="muted">None yet</span>@endforelse</td>
            <td><details><summary class="small">Edit</summary>
                <form method="post" action="{{ route('app.setup.levels.update', $l) }}" class="filters" style="margin-top:6px">@csrf @method('put')
                    <label class="field">Code<input type="text" name="code" value="{{ $l->code }}" required></label>
                    <label class="field">Name<input type="text" name="name" value="{{ $l->name }}" required></label>
                    <label class="field">Order<input type="number" name="sequence" value="{{ $l->sequence }}" min="1"></label>
                    <input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" @checked($l->is_active)> Active</label>
                    <button class="btn sm" type="submit">Save</button></form>
                <form method="post" action="{{ route('app.setup.levels.subjects', $l) }}" style="margin-top:8px">@csrf @method('put')
                    <div class="table-wrap"><table><thead><tr><th>{{ $t('subject') }}</th><th>Taught</th><th>Optional</th></tr></thead><tbody>
                    @foreach ($subjects->where('is_active', true) as $s)
                        @php($has = $l->subjects->firstWhere('id', $s->id))
                        <tr><td>{{ $s->name }}</td><td><input type="checkbox" name="subjects[]" value="{{ $s->id }}" @checked($has) aria-label="Teach {{ $s->name }}"></td>
                            <td><input type="checkbox" name="optional[]" value="{{ $s->id }}" @checked($has?->pivot->is_optional) aria-label="{{ $s->name }} optional"></td></tr>
                    @endforeach</tbody></table></div>
                    <button class="btn sm primary" type="submit">Save {{ strtolower($t('subjects')) }}</button></form></details></td></tr>
    @endforeach</tbody></table></div>
    <form method="post" action="{{ route('app.setup.levels.store', $p) }}" class="filters" style="margin-top:10px">@csrf
        <label class="field">New {{ strtolower($t('level')) }}<input type="text" name="name" required placeholder="{{ $t('level') }} name"></label>
        <label class="field">Code<input type="text" name="code" required maxlength="20"></label>
        <label class="field">Order<input type="number" name="sequence" value="{{ ($p->levels->max('sequence') ?? 0) + 1 }}" min="1"></label>
        <input type="hidden" name="is_active" value="1"><button class="btn" type="submit">Add</button></form>
</section>
@endforeach

<div class="cols">
<form class="card" method="post" action="{{ route('app.setup.programs.store') }}">@csrf
    <h2>Add a {{ strtolower($t('program')) }}</h2>
    <div class="form">
        <label class="field">Code<input type="text" name="code" required maxlength="20" placeholder="{{ $t('program') === 'Course' ? 'JEE' : 'BCOM' }}"></label>
        <label class="field">Name<input type="text" name="name" required></label>
        <label class="field">Grading<select name="grading_scheme_id"><option value="">Default</option>@foreach ($schemes as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
        <label class="field wide">Description<input type="text" name="description"></label>
        <input type="hidden" name="is_active" value="1">
    </div><p><button class="btn primary" type="submit">Add</button></p>
</form>
<section class="card"><h2>{{ $t('subjects') }}</h2>
    <div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Type</th><th class="num">Credits</th><th></th></tr></thead><tbody>
    @foreach ($subjects as $s)
        <tr><td>{{ $s->code }}</td><td>{{ $s->name }} @unless ($s->is_active)<span class="chip closed">inactive</span>@endunless</td><td class="small">{{ config('academic.subject_types.'.$s->type) }}</td><td class="num">{{ $s->credits }}</td>
            <td><details><summary class="small">Edit</summary><form method="post" action="{{ route('app.setup.subjects.update', $s) }}" class="stack" style="margin-top:6px">@csrf @method('put')
                <input type="text" name="code" value="{{ $s->code }}" required aria-label="Code"><input type="text" name="name" value="{{ $s->name }}" required aria-label="Name">
                <select name="type" aria-label="Type">@foreach (config('academic.subject_types') as $k => $l)<option value="{{ $k }}" @selected($s->type === $k)>{{ $l }}</option>@endforeach</select>
                <input type="number" name="credits" value="{{ $s->credits }}" step="0.5" min="0" aria-label="Credits">
                <input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" @checked($s->is_active)> Active</label>
                <button class="btn sm" type="submit">Save</button></form></details></td></tr>
    @endforeach</tbody></table></div>
    <form method="post" action="{{ route('app.setup.subjects.store') }}" class="filters" style="margin-top:10px">@csrf
        <label class="field">Code<input type="text" name="code" required maxlength="20"></label>
        <label class="field">Name<input type="text" name="name" required></label>
        <label class="field">Type<select name="type">@foreach (config('academic.subject_types') as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
        <label class="field">Credits <span class="hint">colleges</span><input type="number" name="credits" step="0.5" min="0"></label>
        <input type="hidden" name="is_active" value="1"><button class="btn" type="submit">Add</button></form>
</section>
</div>
@endsection
