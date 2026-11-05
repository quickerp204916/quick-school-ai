@extends('academic::layout')
@section('title', 'Outstanding bills')
@section('content')
<div class="head"><div><h1>Outstanding bills</h1><p class="muted" style="margin:4px 0 0">Posted invoices not yet fully {{ $side === 'payable' ? 'paid' : 'received' }}, by how long past the due date.</p></div></div>
@include('accounting::partials.report-tabs')
<nav class="toolbar"><a class="btn sm {{ $side === 'receivable' ? 'primary' : '' }}" href="{{ route('app.accounts.reports.outstanding', ['side' => 'receivable']) }}">To receive</a><a class="btn sm {{ $side === 'payable' ? 'primary' : '' }}" href="{{ route('app.accounts.reports.outstanding', ['side' => 'payable']) }}">To pay</a></nav>
<div class="grid">@foreach (['Not due', '1–30 days', '31–60 days', '61–90 days', 'Over 90 days'] as $b)<div class="card kpi"><div class="label">{{ $b }}</div><div class="value" style="font-size:20px">{{ $money($buckets[$b] ?? 0) }}</div></div>@endforeach</div>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Bill</th><th>Party</th><th>Date</th><th>Due</th><th class="num">Amount</th><th class="num">Outstanding</th><th>Age</th></tr></thead><tbody>
@forelse ($rows as $r)<tr><td><a href="{{ route('app.accounts.invoices.show', $r['inv']) }}">{{ $r['inv']->number }}</a></td><td>{{ $r['inv']->party->name }}</td><td>{{ $r['inv']->date->format('j M Y') }}</td><td>{{ $r['inv']->due_date?->format('j M Y') ?? '—' }}</td>
    <td class="num">{{ $money($r['inv']->net) }}</td><td class="num"><b>{{ $money($r['due']) }}</b></td><td><span class="chip {{ $r['days'] > 60 ? 'absent' : ($r['days'] > 0 ? 'warning' : '') }}">{{ $r['bucket'] }}</span></td></tr>
@empty<tr><td colspan="7" class="empty">Nothing outstanding.</td></tr>@endforelse</tbody></table></div></section>
@endsection
