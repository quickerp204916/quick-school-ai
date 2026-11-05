@extends('academic::layout')
@section('title', 'Opening balances')
@section('content')
<div class="head"><div><h1>Opening balances</h1><p class="muted" style="margin:4px 0 0">Balances brought forward on {{ $first?->starts_on->format('j M Y') }}, when you start using Quick School AI for accounts. Income and expense ledgers start at zero.</p></div></div>
@include('accounting::partials.setup-tabs')
<div class="grid">
    <div class="card kpi"><div class="label">Debit total</div><div class="value">{{ $money($summary['debit']) }}</div></div>
    <div class="card kpi"><div class="label">Credit total</div><div class="value">{{ $money($summary['credit']) }}</div></div>
    <div class="card kpi"><div class="label">Difference</div><div class="value" style="color:{{ $summary['difference'] ? 'var(--bad)' : 'var(--good)' }}">{{ $money(abs($summary['difference'])) }}</div><div class="small muted">{{ $summary['difference'] ? 'Must be zero before the books are locked' : 'Debits and credits agree' }}</div></div>
</div>
<form class="card" method="post" action="{{ route('app.accounts.opening.save') }}">@csrf
    <div class="table-wrap"><table><thead><tr><th>Ledger</th><th class="num">Debit (₹)</th><th class="num">Credit (₹)</th></tr></thead><tbody>
    @foreach ($groups as $g)@if ($g->ledgers->isNotEmpty())
        <tr><td colspan="3"><b>{{ $g->name }}</b></td></tr>
        @foreach ($g->ledgers as $l)<tr><td style="padding-left:24px">{{ $l->name }} <span class="small muted">{{ $l->code }}</span></td>
            <td class="num"><input class="mark" type="text" inputmode="decimal" name="opening[{{ $l->id }}][dr]" value="{{ $l->opening_balance > 0 ? \Modules\Accounting\Support\Amount::input($l->opening_balance) : '' }}" aria-label="{{ $l->name }} debit"></td>
            <td class="num"><input class="mark" type="text" inputmode="decimal" name="opening[{{ $l->id }}][cr]" value="{{ $l->opening_balance < 0 ? \Modules\Accounting\Support\Amount::input(-$l->opening_balance) : '' }}" aria-label="{{ $l->name }} credit"></td></tr>@endforeach
    @endif @endforeach
    </tbody></table></div>
    <div class="toolbar" style="margin-top:12px"><button class="btn primary" type="submit">Save opening balances</button>
        <label class="check"><input type="checkbox" name="balance" value="1"> Put any difference into “Opening balance difference”</label></div>
</form>
@endsection
