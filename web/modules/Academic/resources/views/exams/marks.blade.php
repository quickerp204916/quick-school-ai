@extends('academic::layout')
@section('title', 'Marks')
@section('content')
@php($open = $paper->exam->status === 'open')
<div class="head"><div><h1>{{ $paper->subject->name }} · {{ $paper->section->label() }}</h1>
    <p class="muted" style="margin:4px 0 0">{{ $paper->exam->name }} · out of {{ $paper->max_marks + 0 }} · pass {{ $paper->pass_marks + 0 }}</p></div>
    <a class="btn" href="{{ route('app.exams.index') }}">Back</a></div>
@unless ($open)<div class="alert err">{{ $paper->exam->status === 'published' ? 'Results are published. Unpublish the exam to correct marks.' : 'Marks entry is not open yet.' }}</div>@endunless
<form class="card" method="post" action="{{ route('app.papers.marks.save', $paper) }}">@csrf
    <div class="table-wrap"><table><thead><tr><th>Roll</th><th>Student</th><th class="num">Marks</th><th>Absent</th><th>Remark</th></tr></thead><tbody>
    @forelse ($rows as $r)
        @php($id = $r['student']->id)
        <tr><td>{{ $r['roll_no'] ?? '—' }}</td><td>{{ $r['student']->fullName() }}</td>
            <td class="num"><input class="mark" type="text" inputmode="decimal" name="entries[{{ $id }}][marks]" value="{{ old("entries.$id.marks", $r['mark']?->marks !== null ? $r['mark']->marks + 0 : '') }}" @disabled(! $open) aria-label="Marks for {{ $r['student']->fullName() }}">
                @error("entries.$id")<div class="small" style="color:var(--bad)">{{ $message }}</div>@enderror</td>
            <td><input type="checkbox" name="entries[{{ $id }}][absent]" value="1" @checked(old("entries.$id.absent", $r['mark']?->is_absent)) @disabled(! $open) aria-label="{{ $r['student']->fullName() }} absent"></td>
            <td><input type="text" name="entries[{{ $id }}][remarks]" value="{{ old("entries.$id.remarks", $r['mark']?->remarks) }}" maxlength="150" @disabled(! $open) aria-label="Remark"></td></tr>
    @empty<tr><td colspan="5" class="empty">No students sit this paper.</td></tr>@endforelse
    </tbody></table></div>
    <p class="small muted">Leave marks empty if not yet marked. Tick Absent for students who did not sit the paper.</p>
    @if ($open)<button class="btn primary" type="submit">Save marks</button>@endif
</form>
@endsection
