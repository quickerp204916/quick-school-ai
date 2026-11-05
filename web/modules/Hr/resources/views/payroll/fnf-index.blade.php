@extends('academic::layout')
@section('title', 'Full and final settlements')
@section('content')
<div class="head"><h1>Full and final settlements</h1></div>
@include('hr::partials.tabs')
@if ($relieved->isNotEmpty())<p class="alert err">Relieved without a settlement: @foreach ($relieved as $s)<a href="{{ route('app.hr.employees.show', $s) }}">{{ $s->name }}</a>{{ $loop->last ? '' : ', ' }}@endforeach</p>@endif
<section class="card"><div class="table-wrap"><table><thead><tr><th>Employee</th><th>Relieved</th><th class="num">Payable</th><th>Status</th></tr></thead><tbody>
@forelse ($list as $f)<tr><td><a href="{{ route('app.hr.fnf.show', $f) }}">{{ $f->staff->name }}</a></td><td>{{ $f->relieved_on->format('j M Y') }}</td><td class="num">{{ $money($f->net) }}</td><td>{{ $f->status }}</td></tr>
@empty<tr><td colspan="4" class="empty">No settlements.</td></tr>@endforelse</tbody></table></div></section>
@endsection
