@extends('academic::layout')
@section('title', $section->label())
@section('content')
@php($canManage = auth()->user()->can('academic.setup.manage'))
<div class="head"><div><h1>{{ $section->label() }}</h1>
    <p class="muted" style="margin:4px 0 0">{{ $section->session->name }} · {{ $section->branch->name }} · Class teacher {{ $section->classTeacher?->name ?? 'not set' }}
        · {{ $enrollments->where('status', 'active')->count() }}{{ $section->capacity ? ' / '.$section->capacity : '' }} students</p></div>
    <div class="toolbar">
        @can('academic.attendance.mark')<a class="btn" href="{{ route('app.attendance.sheet', ['section' => $section->id]) }}">Mark attendance</a>@endcan
        <a class="btn" href="{{ route('app.timetable.section', ['section' => $section->id, 'session' => $section->session_id]) }}">Timetable</a>
        @can('academic.students.manage')<a class="btn" href="{{ route('app.students.create', ['section' => $section->id]) }}">Add student</a>@endcan
        @if ($canManage)<a class="btn" href="{{ route('app.sections.edit', $section) }}">Edit</a>@endif
    </div></div>
<div class="cols">
<section class="card" style="flex:2 1 480px"><div class="head"><h2>Students</h2>
    @if ($canManage)<form method="post" action="{{ route('app.sections.roll-numbers', $section) }}" class="toolbar">@csrf
        <select name="order" aria-label="Roll number order"><option value="name">By name</option><option value="admission">By admission number</option></select>
        <button class="btn sm" type="submit">Number rolls</button></form>@endif</div>
    <div class="table-wrap"><table><thead><tr><th>Roll</th><th>Name</th><th>Admission no</th><th>Joined</th><th>Status</th></tr></thead><tbody>
    @forelse ($enrollments as $e)
        <tr><td>{{ $e->roll_no ?? '—' }}</td><td><a href="{{ route('app.students.show', $e->student) }}">{{ $e->student->fullName() }}</a></td><td>{{ $e->student->admission_no }}</td>
            <td>{{ $e->joined_on->format('j M Y') }}</td><td><span class="chip {{ $e->status }}">{{ str_replace('_', ' ', $e->status) }}</span></td></tr>
    @empty<tr><td colspan="5" class="empty">No students yet.</td></tr>@endforelse</tbody></table></div>
</section>
<section class="card" style="flex:1 1 340px"><h2>{{ $t('subjects') }} and teachers</h2>
    <div class="table-wrap"><table><thead><tr><th>{{ $t('subject') }}</th><th>Teacher</th><th class="num">Per week</th>@if ($canManage)<th></th>@endif</tr></thead><tbody>
    @forelse ($section->subjects->sortBy('subject.name') as $row)
        <tr><td>{{ $row->subject->name }}</td><td>{{ $row->teacher?->name ?? '—' }}</td><td class="num">{{ $row->periods_per_week ?? '—' }}</td>
            @if ($canManage)<td class="toolbar"><details><summary class="small">Change</summary>
                <form method="post" action="{{ route('app.sections.subjects.store', $section) }}" class="stack" style="margin-top:6px">@csrf
                    <input type="hidden" name="subject_id" value="{{ $row->subject_id }}">
                    <select name="teacher_id" aria-label="Teacher"><option value="">—</option>@foreach ($teachers as $tc)<option value="{{ $tc->id }}" @selected($row->teacher_id === $tc->id)>{{ $tc->name }}</option>@endforeach</select>
                    <input type="number" name="periods_per_week" value="{{ $row->periods_per_week }}" min="1" max="40" aria-label="Periods per week">
                    <button class="btn sm" type="submit">Save</button></form></details>
                <form class="inline" method="post" action="{{ route('app.sections.subjects.destroy', $row) }}">@csrf @method('delete')<button class="btn sm danger" type="submit" aria-label="Remove {{ $row->subject->name }}">Remove</button></form></td>@endif</tr>
    @empty<tr><td colspan="4" class="empty">No {{ strtolower($t('subjects')) }}.</td></tr>@endforelse</tbody></table></div>
    @if ($canManage && $subjects->isNotEmpty())
    <form method="post" action="{{ route('app.sections.subjects.store', $section) }}" class="filters" style="margin-top:10px">@csrf
        <label class="field">{{ $t('subject') }}<select name="subject_id">@foreach ($subjects as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
        <label class="field">Teacher<select name="teacher_id"><option value="">—</option>@foreach ($teachers as $tc)<option value="{{ $tc->id }}">{{ $tc->name }}</option>@endforeach</select></label>
        <label class="field">Per week<input type="number" name="periods_per_week" min="1" max="40"></label>
        <button class="btn" type="submit">Add</button></form>
    @endif
</section>
</div>
@endsection
