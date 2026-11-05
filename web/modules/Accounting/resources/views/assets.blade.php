@extends('academic::layout')
@section('title', 'Fixed assets')
@section('content')
<div class="head"><div><h1>Fixed assets</h1><p class="muted" style="margin:4px 0 0">Asset register and monthly depreciation. Each asset is charged once per month, even if the run is repeated.</p></div></div>
@include('accounting::partials.setup-tabs')
<section class="card"><div class="table-wrap"><table><thead><tr><th>Code</th><th>Asset</th><th>Ledger</th><th>In use from</th><th>Method</th><th class="num">Cost</th><th class="num">Book value</th></tr></thead><tbody>
@forelse ($assets as $a)<tr><td>{{ $a->code }}</td><td>{{ $a->name }}</td><td>{{ $a->assetLedger->name }}</td><td>{{ $a->put_to_use_on->format('j M Y') }}</td><td>{{ strtoupper($a->method) }} {{ $a->rate_bp / 100 }}%</td>
    <td class="num">{{ $money($a->cost) }}</td><td class="num">{{ $money($book[$a->id]) }}</td></tr>
@empty<tr><td colspan="7" class="empty">No assets registered.</td></tr>@endforelse</tbody></table></div>
@can('academic.accounts.post')<form method="post" action="{{ route('app.accounts.assets.depreciate') }}" class="filters" style="margin-top:12px">@csrf
    <label class="field">Month<input type="month" name="period" value="{{ today()->subMonthNoOverflow()->format('Y-m') }}" required style="height:38px;border:1px solid var(--border-strong);border-radius:8px;padding:0 10px"></label>
    <button class="btn primary" type="submit">Post depreciation</button></form>@endcan</section>
@can('academic.accounts.configure')
<form class="card" method="post" action="{{ route('app.accounts.assets.store') }}">@csrf<h2>Register an asset</h2>
    <p class="small muted" style="margin-top:-6px">Record the purchase itself with a purchase invoice or payment voucher to the asset ledger; this adds it to the register for depreciation.</p>
    <div class="form">
        <label class="field">Code<input type="text" name="code" required maxlength="30" placeholder="BUS-03"></label><label class="field">Name<input type="text" name="name" required placeholder="School bus UP16 AB 1234"></label>
        <label class="field">Asset ledger<select name="asset_ledger_id">@foreach ($ledgers as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select></label>
        <label class="field">In use from<input type="date" name="put_to_use_on" required max="{{ today()->format('Y-m-d') }}"></label>
        <label class="field">Cost (₹)<input type="text" name="cost" inputmode="decimal" required></label><label class="field">Salvage value (₹)<input type="text" name="salvage" inputmode="decimal" value="0"></label>
        <label class="field">Method<select name="method"><option value="slm">Straight line</option><option value="wdv">Written down value</option></select></label>
        <label class="field">Rate % a year<input type="number" name="rate" step="0.01" min="0.01" max="100" required></label>
        <label class="field">Cost centre<select name="cost_centre_id"><option value="">—</option>@foreach ($costCentres as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
    </div><p><button class="btn" type="submit">Register</button></p>
</form>@endcan
@endsection
