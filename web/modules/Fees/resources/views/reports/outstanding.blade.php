@extends('academic::layout')
@section('title', 'Outstanding fees')
@section('content')
<div class="head"><h1>Outstanding fees</h1><a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">Download CSV</a></div>
@include('fees::partials.tabs') @include('fees::reports.tabs')
<form method="get" class="filters"><label class="field">As on<input type="date" name="as_on" value="{{ $asOn }}"></label>
    <label class="field">Section<select name="section"><option value="">All</option>@foreach ($sections as $s)<option value="{{ $s->id }}" @selected(request('section') === $s->id)>{{ $s->label() }}</option>@endforeach</select></label><button class="btn" type="submit">Show</button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Student</th><th>Class</th><th>Parent</th>@foreach (\Modules\Fees\Services\FeeReports::BUCKETS as $l)<th class="num">{{ $l }}</th>@endforeach<th class="num">Total</th></tr></thead><tbody>
@forelse ($rows as $r)
    <tr><td><a href="{{ route('app.fees.students.show', $r['student']) }}">{{ $r['student']->fullName() }}</a></td><td>{{ $r['student']->currentEnrollment?->section?->label() }}</td><td>{{ $r['student']->primaryGuardian()?->phone }}</td>
    @foreach (array_keys(\Modules\Fees\Services\FeeReports::BUCKETS) as $k)<td class="num">{{ $r[$k] ? $money($r[$k]) : '' }}</td>@endforeach<td class="num"><b>{{ $money($r['total']) }}</b></td></tr>
@empty<tr><td colspan="9" class="empty">Nothing outstanding.</td></tr>@endforelse
@if ($rows->isNotEmpty())<tr><td colspan="3"><b>Total</b></td>@foreach (array_keys(\Modules\Fees\Services\FeeReports::BUCKETS) as $k)<td class="num"><b>{{ $money($rows->sum($k)) }}</b></td>@endforeach<td class="num"><b>{{ $money($rows->sum('total')) }}</b></td></tr>@endif
</tbody></table></div></section>
@endsection
