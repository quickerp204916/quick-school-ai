@extends('academic::layout')
@section('title', 'Unusual entries')
@section('content')
<div class="head"><h1>Unusual entries</h1></div>
@include('ai::partials.tabs')
<form class="toolbar" method="get"><label class="field">From<input type="date" name="from" value="{{ $from }}"></label><label class="field">To<input type="date" name="to" value="{{ $to }}"></label><button class="btn" type="submit">Check</button></form>
<p class="small muted">Rule-based checks of posted vouchers: duplicate payments, payments split under the approval limit, cash above ₹10,000, self-approval, back-dating, round amounts, frequent reversals, large waivers and spending spikes. A finding is a prompt to look, not proof of a mistake.</p>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Severity</th><th>Finding</th><th>Vouchers</th><th class="num">Amount</th></tr></thead><tbody>
@forelse ($findings as $f)<tr><td><span class="chip {{ $f['severity'] }}">{{ $f['severity'] }}</span></td><td><b>{{ $f['title'] }}</b><br><span class="small">{{ $f['detail'] }}</span></td><td class="small">{{ implode(', ', $f['vouchers']) }}</td><td class="num">{{ $f['amount_display'] }}</td></tr>
@empty<tr><td colspan="4" class="empty">Nothing unusual found in this period.</td></tr>@endforelse</tbody></table></div></section>
@endsection
