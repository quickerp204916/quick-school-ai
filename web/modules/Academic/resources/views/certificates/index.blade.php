@extends('academic::layout')
@section('title', 'Certificates')
@section('content')
<div class="head"><div><h1>Certificates</h1><p class="muted" style="margin:4px 0 0">Issue from a student’s page. Numbers run per type and financial year.</p></div>
    <a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">Download CSV</a></div>
<form method="get" class="filters">
    <label class="field">From<input type="date" name="from" value="{{ $from }}"></label><label class="field">To<input type="date" name="to" value="{{ $to }}"></label>
    <label class="field">Type<select name="type"><option value="">All</option>@foreach (config('academic.certificate_types') as $k => $c)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $c['label'] }}</option>@endforeach</select></label>
    <button class="btn" type="submit">Show</button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Number</th><th>Type</th><th>Student</th><th>Issued</th><th>Purpose</th><th>Status</th></tr></thead><tbody>
@forelse ($list as $c)
    <tr><td><a href="{{ route('app.certificates.show', $c) }}">{{ $c->number }}</a></td><td>{{ config('academic.certificate_types.'.$c->type.'.label') }}</td>
        <td>{{ $c->student->fullName() }} <span class="small muted">{{ $c->student->admission_no }}</span></td><td>{{ $c->issued_on->format('j M Y') }}</td><td>{{ $c->purpose }}</td>
        <td><span class="chip {{ $c->status }}">{{ $c->status }}</span></td></tr>
@empty<tr><td colspan="6" class="empty">No certificates in this period.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
