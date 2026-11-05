@extends('academic::layout')
@section('title', 'Fee collections')
@section('content')
<div class="head"><h1>Fee collections</h1><a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">Download CSV</a></div>
@include('fees::partials.tabs') @include('fees::reports.tabs')
<form method="get" class="filters"><label class="field">From<input type="date" name="from" value="{{ $from }}"></label><label class="field">To<input type="date" name="to" value="{{ $to }}"></label><button class="btn" type="submit">Show</button></form>
<div class="grid"><div class="card kpi"><div class="label">Collected</div><div class="value">{{ $money($total) }}</div></div><div class="card kpi"><div class="label">Refunded</div><div class="value">{{ $money($refunded) }}</div></div>
@foreach ($by_mode as $m => $a)<div class="card kpi"><div class="label">{{ \Modules\Fees\Models\FeePayment::MODES[$m] }}</div><div class="value">{{ $money($a) }}</div></div>@endforeach</div>
<section class="card"><h2>By day</h2><div class="table-wrap"><table><tbody>@forelse ($by_day as $d => $a)<tr><td>{{ \Carbon\Carbon::parse($d)->format('D j M Y') }}</td><td class="num">{{ $money($a) }}</td></tr>@empty<tr><td class="empty">No collections.</td></tr>@endforelse</tbody></table></div></section>
@endsection
