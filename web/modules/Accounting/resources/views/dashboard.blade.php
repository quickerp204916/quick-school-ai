@extends('academic::layout')
@section('title', 'Accounts')
@section('content')
<div class="head"><div><h1>Accounts</h1><p class="muted" style="margin:4px 0 0">Financial year {{ $fy?->name }} · books locked up to {{ $settings->locked_until?->format('j M Y') ?? 'nothing yet' }}</p></div>
    <div class="toolbar">@can('academic.accounts.post')
        @foreach (['receipt' => 'Receipt', 'payment' => 'Payment', 'contra' => 'Contra', 'journal' => 'Journal'] as $k => $l)<a class="btn {{ $k === 'receipt' ? 'primary' : '' }}" href="{{ route('app.accounts.vouchers.create', ['type' => $k]) }}">{{ $l }}</a>@endforeach
        <a class="btn" href="{{ route('app.accounts.invoices.create', ['kind' => 'expense']) }}">Expense</a>@endcan</div></div>
@if ($pending)<div class="alert err" style="background:var(--warn-bg);color:var(--warn)">{{ $pending }} {{ \Illuminate\Support\Str::plural('voucher', $pending) }} waiting for approval. <a href="{{ route('app.accounts.approvals') }}">Review</a></div>@endif
<div class="grid">
    <div class="card kpi"><div class="label">Income this month</div><div class="value">{{ $money($pl['total_income']) }}</div></div>
    <div class="card kpi"><div class="label">Expenses this month</div><div class="value">{{ $money($pl['total_expense']) }}</div></div>
    <div class="card kpi"><div class="label">Surplus this month</div><div class="value" style="color:{{ $pl['surplus'] < 0 ? 'var(--bad)' : 'inherit' }}">{{ $money($pl['surplus']) }}</div></div>
    <div class="card kpi"><div class="label">To receive · to pay</div><div class="value" style="font-size:19px">{{ $money($receivable) }} · {{ $money($payable) }}</div>
        <div class="small"><a href="{{ route('app.accounts.reports.outstanding') }}">Outstanding bills</a></div></div>
</div>
<div class="cols">
    <section class="card"><h2>Cash, bank and gateways</h2><div class="table-wrap"><table><tbody>
        @foreach ($cash as $c)<tr><td><a href="{{ route($c['ledger']->type === 'cash' ? 'app.accounts.reports.cash-book' : ($c['ledger']->type === 'bank' ? 'app.accounts.reports.bank-book' : 'app.accounts.reports.ledger'), ['ledger' => $c['ledger']->id]) }}">{{ $c['ledger']->name }}</a>
            <span class="chip">{{ $c['ledger']->type }}</span></td><td class="num">{{ $drcr($c['balance']) }}</td></tr>@endforeach
    </tbody></table></div></section>
    <section class="card"><h2>Recently posted</h2><div class="table-wrap"><table><tbody>
        @forelse ($recent as $v)<tr><td><a href="{{ route('app.accounts.vouchers.show', $v) }}">{{ $v->number }}</a><div class="small muted">{{ $v->date->format('j M') }} · {{ \Illuminate\Support\Str::limit($v->narration, 50) }}</div></td>
            <td class="num">{{ $money($v->total) }} @if ($v->status === 'reversed')<span class="chip closed">reversed</span>@endif</td></tr>
        @empty<tr><td class="empty">Nothing posted yet.</td></tr>@endforelse
    </tbody></table></div></section>
</div>
@endsection
