@extends('academic::layout')
@section('title', 'Cost centres')
@section('content')
<div class="head"><div><h1>Cost centre report</h1><p class="muted" style="margin:4px 0 0">Income and expenses tagged to each cost centre.</p></div></div>
@include('accounting::partials.report-tabs')
@include('accounting::partials.range', ['from' => $from, 'to' => $to, 'branches' => $branches])
<section class="card"><div class="table-wrap"><table><thead><tr><th>Cost centre</th><th class="num">Income</th><th class="num">Expenses</th><th class="num">Net</th></tr></thead><tbody>
@forelse ($rows as $r)<tr><td>{{ $r['cost_centre']?->name }}</td><td class="num">{{ $money($r['income']) }}</td><td class="num">{{ $money($r['expense']) }}</td><td class="num">{{ $money($r['net']) }}</td></tr>
@empty<tr><td colspan="4" class="empty">Nothing tagged to a cost centre in this period.</td></tr>@endforelse</tbody></table></div></section>
@endsection
