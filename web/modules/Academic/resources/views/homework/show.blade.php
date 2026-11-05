@extends('academic::layout')
@section('title', $hw->title)
@section('content')
<div class="head"><div><h1>{{ $hw->title }} <span class="chip {{ $hw->status }}">{{ $hw->status }}</span></h1>
    <p class="muted" style="margin:4px 0 0">{{ $hw->section->label() }} · {{ $hw->subject->name }} · set {{ $hw->assigned_on->format('j M') }} · due {{ $hw->due_on->format('l j M') }} · {{ $hw->teacher?->name }}</p></div>
    <div class="toolbar">@if ($hw->status === 'published')<a class="btn" href="{{ route('app.homework.edit', $hw) }}">Edit</a>
        <form class="inline" method="post" action="{{ route('app.homework.cancel', $hw) }}">@csrf<button class="btn danger" type="submit">Cancel homework</button></form>@endif</div></div>
@if ($hw->details)<section class="card"><p style="margin:0;white-space:pre-line">{{ $hw->details }}</p></section>@endif
<form class="card" method="post" action="{{ route('app.homework.check', $hw) }}">@csrf
    <div class="head"><h2>Submissions · {{ $done }} of {{ $total }} done {{ $percent !== null ? '('.$percent.'%)' : '' }}</h2></div>
    <div class="table-wrap"><table><thead><tr><th>Roll</th><th>Student</th><th>Status</th><th>Grade</th><th>Remark</th></tr></thead><tbody>
    @forelse ($rows as $r)
        @php($id = $r['student']->id)
        <tr><td>{{ $r['roll_no'] ?? '—' }}</td><td>{{ $r['student']->fullName() }}@if ($r['submission']?->content)<div class="small muted">{{ \Illuminate\Support\Str::limit($r['submission']->content, 80) }}</div>@endif</td>
            <td><select name="rows[{{ $id }}][status]" aria-label="Status for {{ $r['student']->fullName() }}">@foreach (['pending' => 'Not done', 'submitted' => 'Submitted', 'late' => 'Late', 'checked' => 'Checked', 'redo' => 'Redo'] as $k => $l)<option value="{{ $k }}" @selected(($r['submission']?->status ?? 'pending') === $k)>{{ $l }}</option>@endforeach</select></td>
            <td><input type="text" name="rows[{{ $id }}][grade]" value="{{ $r['submission']?->grade }}" maxlength="10" style="width:80px" aria-label="Grade"></td>
            <td><input type="text" name="rows[{{ $id }}][remarks]" value="{{ $r['submission']?->remarks }}" maxlength="200" aria-label="Remark"></td></tr>
    @empty<tr><td colspan="5" class="empty">No students.</td></tr>@endforelse</tbody></table></div>
    @if ($rows->isNotEmpty())<p><button class="btn primary" type="submit">Save</button></p>@endif
</form>
@endsection
