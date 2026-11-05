@extends('academic::layout')
@section('title', 'Class strength')
@section('content')
<div class="head"><div><h1>Class strength</h1><p class="muted" style="margin:4px 0 0">{{ $session->name }} · {{ $rows->sum('total') }} students</p></div>
    <a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">Download CSV</a></div>
<div class="filters">@include('academic::partials.session-filter')
    <form method="get" class="filters"><input type="hidden" name="session" value="{{ $session->id }}">
        <label class="field">Branch<select name="branch" onchange="this.form.submit()"><option value="">All</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(request('branch') === $b->id)>{{ $b->name }}</option>@endforeach</select></label></form></div>
<section class="card"><div class="table-wrap"><table><thead><tr><th>{{ $t('section') }}</th><th>Branch</th><th>Class teacher</th><th class="num">Boys</th><th class="num">Girls</th><th class="num">Other</th><th class="num">Total</th><th class="num">Capacity</th><th class="num">Vacant</th></tr></thead><tbody>
@forelse ($rows as $r)
    <tr><td>{{ $r['section']->label() }}</td><td>{{ $r['section']->branch->name }}</td><td>{{ $r['section']->classTeacher?->name }}</td><td class="num">{{ $r['male'] }}</td><td class="num">{{ $r['female'] }}</td><td class="num">{{ $r['other'] }}</td>
        <td class="num"><b>{{ $r['total'] }}</b></td><td class="num">{{ $r['capacity'] ?? '—' }}</td><td class="num">{{ $r['vacant'] ?? '—' }}</td></tr>
@empty<tr><td colspan="9" class="empty">No {{ strtolower($t('sections')) }}.</td></tr>@endforelse
@if ($rows->isNotEmpty())<tr><td colspan="3"><b>Total</b></td><td class="num"><b>{{ $rows->sum('male') }}</b></td><td class="num"><b>{{ $rows->sum('female') }}</b></td><td class="num"><b>{{ $rows->sum('other') }}</b></td><td class="num"><b>{{ $rows->sum('total') }}</b></td><td class="num">{{ $rows->sum('capacity') }}</td><td class="num">{{ $rows->sum('vacant') }}</td></tr>@endif
</tbody></table></div></section>
@endsection
