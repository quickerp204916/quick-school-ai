@extends('academic::layout')
@section('title', 'Concessions given')
@section('content')
<div class="head"><h1>Concessions and scholarships</h1></div>
@include('fees::partials.tabs') @include('fees::reports.tabs')
<section class="card"><div class="table-wrap"><table><thead><tr><th>Student</th><th>Concession</th><th>Status</th><th>Reason</th><th class="num">Given on invoices</th></tr></thead><tbody>
@forelse ($rows as $x)<tr><td>{{ $x['row']->plan->student->fullName() }}</td><td>{{ $x['row']->concession->label() }}</td><td>@include('fees::partials.status', ['s' => $x['row']->status])</td><td>{{ $x['row']->reason }}</td><td class="num">{{ $money($x['given']) }}</td></tr>
@empty<tr><td colspan="5" class="empty">No concessions given.</td></tr>@endforelse</tbody></table></div></section>
@endsection
