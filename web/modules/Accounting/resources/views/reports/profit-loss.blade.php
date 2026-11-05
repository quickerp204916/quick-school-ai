@extends('academic::layout')
@section('title', 'Profit and loss')
@section('content')
<div class="head"><div><h1>Income and expenditure</h1><p class="muted" style="margin:4px 0 0">{{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}</p></div></div>
@include('accounting::partials.report-tabs')
@component('accounting::partials.range', ['from' => $from, 'to' => $to, 'branches' => $branches])
    <label class="field">Cost centre<select name="cost_centre"><option value="">All</option>@foreach ($costCentres as $c)<option value="{{ $c->id }}" @selected(request('cost_centre') === $c->id)>{{ $c->name }}</option>@endforeach</select></label>
@endcomponent
<div class="cols">
<section class="card"><h2>Income</h2><div class="table-wrap"><table><tbody>
    @foreach ($pl['income'] as $n)@include('accounting::partials.group-tree', ['node' => $n, 'depth' => 0])@endforeach
    <tr><td><b>Total income</b></td><td class="num"><b>{{ $money($pl['total_income']) }}</b></td></tr></tbody></table></div></section>
<section class="card"><h2>Expenditure</h2><div class="table-wrap"><table><tbody>
    @foreach ($pl['expense'] as $n)@include('accounting::partials.group-tree', ['node' => $n, 'depth' => 0])@endforeach
    <tr><td><b>Total expenditure</b></td><td class="num"><b>{{ $money($pl['total_expense']) }}</b></td></tr></tbody></table></div></section>
</div>
<div class="card kpi"><div class="label">{{ $pl['surplus'] >= 0 ? 'Surplus (excess of income over expenditure)' : 'Deficit' }}</div><div class="value" style="color:{{ $pl['surplus'] < 0 ? 'var(--bad)' : 'var(--good)' }}">{{ $money(abs($pl['surplus'])) }}</div></div>
@endsection
