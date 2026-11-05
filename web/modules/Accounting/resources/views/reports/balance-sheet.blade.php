@extends('academic::layout')
@section('title', 'Balance sheet')
@section('content')
<div class="head"><div><h1>Balance sheet</h1><p class="muted" style="margin:4px 0 0">As on {{ \Illuminate\Support\Carbon::parse($asOn)->format('j F Y') }}</p></div></div>
@include('accounting::partials.report-tabs')
@include('accounting::partials.range', ['asOn' => $asOn, 'branches' => $branches])
@if ($bs['difference'] !== 0)<div class="alert err">The balance sheet is out by {{ $money(abs($bs['difference'])) }} because opening balances do not agree. <a href="{{ route('app.accounts.opening') }}">Check opening balances</a>.</div>@endif
<div class="cols">
<section class="card"><h2>Capital and liabilities</h2><div class="table-wrap"><table><tbody>
    @foreach ($bs['equity'] as $n)@include('accounting::partials.group-tree', ['node' => $n, 'depth' => 0])@endforeach
    <tr><td style="padding-left:28px">Surplus to date (income less expenditure)</td><td class="num">{{ $money($bs['surplus']) }}</td></tr>
    @foreach ($bs['liabilities'] as $n)@include('accounting::partials.group-tree', ['node' => $n, 'depth' => 0])@endforeach
    <tr><td><b>Total</b></td><td class="num"><b>{{ $money($bs['total_liabilities']) }}</b></td></tr></tbody></table></div></section>
<section class="card"><h2>Assets</h2><div class="table-wrap"><table><tbody>
    @foreach ($bs['assets'] as $n)@include('accounting::partials.group-tree', ['node' => $n, 'depth' => 0])@endforeach
    <tr><td><b>Total</b></td><td class="num"><b>{{ $money($bs['total_assets']) }}</b></td></tr></tbody></table></div></section>
</div>
@endsection
