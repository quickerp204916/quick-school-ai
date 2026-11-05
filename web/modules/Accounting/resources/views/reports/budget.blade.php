@extends('academic::layout')
@section('title', 'Budget vs actual')
@section('content')
<div class="head"><div><h1>Budget against actual · {{ $fy?->name }}</h1></div><a class="btn" href="{{ route('app.accounts.budgets', ['year' => $fy?->id]) }}">Edit budgets</a></div>
@include('accounting::partials.report-tabs')
<section class="card"><div class="table-wrap"><table><thead><tr><th>Ledger</th><th>Cost centre</th><th class="num">Budget</th><th class="num">Actual</th><th class="num">Variance</th><th class="num">Used</th></tr></thead><tbody>
@forelse ($rows as $r)<tr><td>{{ $r['budget']->ledger->name }}</td><td>{{ $r['budget']->costCentre?->name ?? 'All' }}</td><td class="num">{{ $money($r['budget']->amount) }}</td><td class="num">{{ $money($r['actual']) }}</td>
    <td class="num" style="color:{{ $r['variance'] < 0 ? 'var(--bad)' : 'inherit' }}">{{ $money($r['variance']) }}</td><td class="num">{{ $r['used'] }}%</td></tr>
@empty<tr><td colspan="6" class="empty">No budgets set for this year.</td></tr>@endforelse</tbody></table></div></section>
@endsection
