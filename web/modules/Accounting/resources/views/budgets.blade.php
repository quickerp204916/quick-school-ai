@extends('academic::layout')
@section('title', 'Budgets')
@section('content')
<div class="head"><div><h1>Budgets {{ $fy?->name }}</h1><p class="muted" style="margin:4px 0 0">Yearly budget per income or expense ledger, optionally per cost centre or branch.</p></div><a class="btn" href="{{ route('app.accounts.reports.budget', ['year' => $fy?->id]) }}">Budget vs actual</a></div>
@include('accounting::partials.setup-tabs')
<section class="card"><div class="table-wrap"><table><thead><tr><th>Ledger</th><th>Cost centre</th><th class="num">Budget</th><th class="num">Actual</th><th class="num">Remaining</th><th>Used</th></tr></thead><tbody>
@forelse ($rows as $r)<tr><td>{{ $r['budget']->ledger->name }}</td><td>{{ $r['budget']->costCentre?->name ?? 'All' }}</td><td class="num">{{ $money($r['budget']->amount) }}</td><td class="num">{{ $money($r['actual']) }}</td>
    <td class="num" style="color:{{ $r['variance'] < 0 ? 'var(--bad)' : 'inherit' }}">{{ $money($r['variance']) }}</td><td style="min-width:120px"><div class="bar"><i style="width:{{ min(100, (int) $r['used']) }}%;{{ $r['used'] > 100 ? 'background:var(--bad)' : '' }}"></i></div><span class="small">{{ $r['used'] }}%</span></td></tr>
@empty<tr><td colspan="6" class="empty">No budgets for this year.</td></tr>@endforelse</tbody></table></div></section>
@can('academic.accounts.configure')
<form class="card" method="post" action="{{ route('app.accounts.budgets.store') }}">@csrf<input type="hidden" name="financial_year_id" value="{{ $fy?->id }}">
    <h2>Set a budget</h2><div class="filters">
    <label class="field">Ledger<select name="ledger_id" required>@foreach ($ledgers as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select></label>
    <label class="field">Cost centre<select name="cost_centre_id"><option value="">All</option>@foreach ($costCentres as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
    <label class="field">Amount for the year (₹)<input type="text" name="amount" inputmode="decimal" required></label><button class="btn primary" type="submit">Save</button></div>
</form>@endcan
@endsection
