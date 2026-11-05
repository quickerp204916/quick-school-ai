@extends('academic::layout')
@section('title', 'Students')
@section('content')
<div class="head"><div><h1>Students</h1><p class="muted" style="margin:4px 0 0">{{ $students->total() }} found · {{ $session->name }}</p></div>
    <div class="toolbar"><a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">Download CSV</a>
        @can('academic.students.manage')<a class="btn primary" href="{{ route('app.students.create') }}">Add student</a>@endcan</div></div>
<form method="get" class="filters">
    <input type="hidden" name="session" value="{{ $session->id }}">
    <label class="field grow">Search<input type="search" name="q" value="{{ request('q') }}" placeholder="Name, admission number, guardian name or phone"></label>
    <label class="field">{{ $t('level') }}<select name="level"><option value="">All</option>@foreach ($levels as $l)<option value="{{ $l->id }}" @selected(request('level') === $l->id)>{{ $l->name }}</option>@endforeach</select></label>
    <label class="field">{{ $t('section') }}<select name="section"><option value="">All</option>@foreach ($sections as $s)<option value="{{ $s->id }}" @selected(request('section') === $s->id)>{{ $s->label() }}</option>@endforeach</select></label>
    <label class="field">Gender<select name="gender"><option value="">All</option><option value="male" @selected(request('gender') === 'male')>Male</option><option value="female" @selected(request('gender') === 'female')>Female</option><option value="other" @selected(request('gender') === 'other')>Other</option></select></label>
    <label class="field">Status<select name="status">@foreach (['active', 'alumni', 'transferred', 'withdrawn', 'all'] as $st)<option value="{{ $st }}" @selected(request('status', 'active') === $st)>{{ ucfirst($st) }}</option>@endforeach</select></label>
    <button class="btn" type="submit">Filter</button>
</form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Admission no</th><th>Name</th><th>{{ $t('section') }}</th><th>Roll</th><th>Guardian</th><th>Status</th></tr></thead><tbody>
@forelse ($students as $s)
    @php($g = $s->guardians->first())
    <tr><td>{{ $s->admission_no }}</td><td><a href="{{ route('app.students.show', $s) }}"><b>{{ $s->fullName() }}</b></a><div class="small muted">{{ ucfirst($s->gender) }} · {{ $s->date_of_birth->format('j M Y') }}</div></td>
        <td>{{ $s->currentEnrollment?->section?->label() ?? '—' }}</td><td>{{ $s->currentEnrollment?->roll_no }}</td>
        <td>{{ $g?->name }}<div class="small muted">{{ $g?->phone }}</div></td><td><span class="chip {{ $s->status }}">{{ $s->status }}</span></td></tr>
@empty<tr><td colspan="6" class="empty">No students match.</td></tr>@endforelse
</tbody></table></div>@include('academic::partials.pager', ['p' => $students])</section>
@endsection
