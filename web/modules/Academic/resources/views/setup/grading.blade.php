@extends('academic::layout')
@section('title', 'Grading')
@section('content')
<div class="head"><div><h1>Grading schemes</h1><p class="muted" style="margin:4px 0 0">A grade applies from its minimum percentage up to the next band.</p></div></div>
@include('academic::setup.nav')
@php($types = ['grades' => 'Letter grades', 'gpa' => 'Grade points (GPA / CGPA)', 'percentage' => 'Percentage and division'])
@foreach ([...$schemes, null] as $scheme)
<form class="card" method="post" action="{{ $scheme ? route('app.setup.grading.update', $scheme) : route('app.setup.grading.store') }}">@csrf @if ($scheme) @method('put') @endif
    <h2>{{ $scheme?->name ?? 'New grading scheme' }} @if ($scheme?->is_default)<span class="chip current">default</span>@endif</h2>
    <div class="filters">
        <label class="field grow">Name<input type="text" name="name" value="{{ $scheme?->name }}" required></label>
        <label class="field">Type<select name="type">@foreach ($types as $k => $l)<option value="{{ $k }}" @selected($scheme?->type === $k)>{{ $l }}</option>@endforeach</select></label>
        <label class="field">Overall pass %<input type="number" name="pass_percent" value="{{ $scheme?->pass_percent ?? 33 }}" min="0" max="100" step="0.01" required></label>
    </div>
    <div class="table-wrap" style="margin-top:10px"><table><thead><tr><th>Grade</th><th>From %</th><th>Grade point</th><th>Remark</th></tr></thead><tbody>
    @php($bands = $scheme ? $scheme->bands->sortByDesc('min_percent')->values() : collect())
    @for ($i = 0; $i < max(10, $bands->count() + 2); $i++)
        @php($b = $bands[$i] ?? null)
        <tr><td><input type="text" name="bands[{{ $i }}][grade]" value="{{ $b?->grade }}" maxlength="12" aria-label="Grade {{ $i + 1 }}"></td>
            <td><input type="number" name="bands[{{ $i }}][min_percent]" value="{{ $b?->min_percent }}" min="0" max="100" step="0.01" aria-label="Minimum percent {{ $i + 1 }}"></td>
            <td><input type="number" name="bands[{{ $i }}][grade_point]" value="{{ $b?->grade_point }}" min="0" max="10" step="0.1" aria-label="Grade point {{ $i + 1 }}"></td>
            <td><input type="text" name="bands[{{ $i }}][remark]" value="{{ $b?->remark }}" maxlength="60" aria-label="Remark {{ $i + 1 }}"></td></tr>
    @endfor</tbody></table></div>
    <p class="small muted">Leave unused rows empty. One band must start at 0%.</p>
    <button class="btn {{ $scheme ? '' : 'primary' }}" type="submit">{{ $scheme ? 'Save' : 'Add scheme' }}</button>
</form>
@endforeach
@endsection
