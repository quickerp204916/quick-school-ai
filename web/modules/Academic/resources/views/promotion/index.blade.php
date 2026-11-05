@extends('academic::layout')
@section('title', 'Promotion')
@section('content')
<div class="head"><div><h1>Promotion to the next session</h1><p class="muted" style="margin:4px 0 0">Suggestions come from each student’s latest published report card. Change any decision before running.</p></div></div>
<form method="get" class="filters">
    <label class="field">From session<select name="from_session">@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected($from->id === $s->id)>{{ $s->name }}</option>@endforeach</select></label>
    <label class="field">To session<select name="to_session"><option value="">Choose</option>@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected($to?->id === $s->id)>{{ $s->name }}</option>@endforeach</select></label>
    <label class="field">{{ $t('section') }}<select name="section"><option value="">Choose</option>@foreach ($sections as $s)<option value="{{ $s->id }}" @selected($section?->id === $s->id)>{{ $s->label() }} · {{ $s->strength }} students</option>@endforeach</select></label>
    <button class="btn" type="submit">Preview</button></form>
@if (! $to)<div class="alert err">Add the next session first in Setup → Sessions.</div>@endif
@if ($preview)
<form class="card" method="post" action="{{ route('app.promotion.run') }}">@csrf
    <input type="hidden" name="section_id" value="{{ $section->id }}"><input type="hidden" name="to_session_id" value="{{ $to->id }}">
    <h2>{{ $section->label() }} → {{ $preview['next_level']?->name ?? 'graduation' }} in {{ $to->name }}</h2>
    @if ($preview['next_level'] && $preview['targets']['promote']->isEmpty())<div class="alert err">No {{ strtolower($t('section')) }} of {{ $preview['next_level']->name }} exists in {{ $to->name }} yet. Create one first.</div>@endif
    <div class="table-wrap"><table><thead><tr><th>Roll</th><th>Student</th><th>Basis</th><th>Decision</th><th>Into</th></tr></thead><tbody>
    @foreach ($preview['rows'] as $r)
        @php($id = $r['student']->id)
        <tr><td>{{ $r['enrollment']->roll_no }}</td><td>{{ $r['student']->fullName() }}</td><td class="small">{{ $r['reason'] }}</td>
            <td><select name="decisions[{{ $id }}][action]" aria-label="Decision for {{ $r['student']->fullName() }}">@foreach (['promote' => 'Promote', 'retain' => 'Retain in same '.strtolower($t('level')), 'graduate' => 'Graduate (alumni)', 'leave' => 'Leaving', 'skip' => 'Decide later'] as $k => $l)
                <option value="{{ $k }}" @selected($r['suggested'] === $k) @disabled($k === 'promote' && ! $preview['next_level'])>{{ $l }}</option>@endforeach</select></td>
            <td><select name="decisions[{{ $id }}][section_id]" aria-label="Into section"><option value="">—</option>
                @foreach ($preview['targets']['promote'] as $sx)<option value="{{ $sx->id }}" @selected($r['suggested'] === 'promote' && $loop->first)>{{ $sx->label() }}</option>@endforeach
                @foreach ($preview['targets']['retain'] as $sx)<option value="{{ $sx->id }}" @selected($r['suggested'] === 'retain' && $loop->first)>{{ $sx->label() }} (same {{ strtolower($t('level')) }})</option>@endforeach</select></td></tr>
    @endforeach</tbody></table></div>
    <p class="small muted">Promoted and retained students keep their admission number and guardians. Roll numbers are assigned fresh.</p>
    <button class="btn primary" type="submit">Run promotion</button>
</form>
@endif
<section class="card"><h2>Recent promotion runs</h2>
    @forelse ($runs as $run)<p class="small" style="margin:0 0 4px">{{ $run->created_at->format('j M Y, g:i a') }} · {{ $run->fromSection?->label() }} → {{ $run->toSession?->name }} ·
        @foreach ($run->counts ?? [] as $k => $n)@if ($n){{ $n }} {{ $k }} @endif @endforeach</p>
    @empty<p class="muted">None yet.</p>@endforelse
</section>
@endsection
