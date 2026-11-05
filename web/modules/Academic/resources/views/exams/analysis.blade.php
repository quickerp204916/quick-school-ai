@extends('academic::layout')
@section('title', 'Results · '.$exam->name)
@section('content')
<div class="head"><div><h1>Results · {{ $exam->name }}</h1><p class="muted" style="margin:4px 0 0">{{ $section?->label() }}</p></div>
    @if ($section)<a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv', 'section' => $section->id]) }}">Download marks CSV</a>@endif</div>
<form method="get" class="filters"><label class="field">{{ $t('section') }}<select name="section" onchange="this.form.submit()">@foreach ($sections as $s)<option value="{{ $s->id }}" @selected($section?->id === $s->id)>{{ $s->label() }}</option>@endforeach</select></label>
    <noscript><button class="btn" type="submit">Show</button></noscript></form>
@if ($data)
<section class="card"><h2>By {{ strtolower($t('subject')) }}</h2><div class="table-wrap"><table><thead><tr><th>{{ $t('subject') }}</th><th class="num">Entered</th><th class="num">Absent</th><th class="num">Average</th><th class="num">Highest</th><th class="num">Lowest</th><th class="num">Pass %</th></tr></thead><tbody>
    @foreach ($data['subjects'] as $r)<tr><td>{{ $r['subject']->name }} <span class="small muted">/ {{ $r['paper']->max_marks + 0 }}</span></td><td class="num">{{ $r['entered'] }}</td><td class="num">{{ $r['absent'] }}</td>
        <td class="num">{{ $r['average'] ?? '—' }}</td><td class="num">{{ $r['highest'] ?? '—' }}</td><td class="num">{{ $r['lowest'] ?? '—' }}</td><td class="num">{{ $r['pass_percent'] ?? '—' }}</td></tr>@endforeach
</tbody></table></div></section>
<section class="card"><h2>Top 5 by total (out of {{ $data['max_total'] + 0 }})</h2>
    @forelse ($data['toppers'] as $i => $r)<p style="margin:0 0 6px">{{ $i + 1 }}. <b>{{ $r['student']->fullName() }}</b> · {{ $r['total'] + 0 }} · {{ $r['percent'] }}%</p>@empty<p class="muted">No marks yet.</p>@endforelse
</section>
@else<div class="card empty">No papers for your {{ strtolower($t('sections')) }} in this exam.</div>@endif
@endsection
