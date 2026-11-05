@extends('academic::layout')
@section('title', 'Trial balance')
@section('content')
<div class="head"><div><h1>Trial balance</h1><p class="muted" style="margin:4px 0 0">{{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}</p></div><a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">Download CSV</a></div>
@include('accounting::partials.report-tabs')
@include('accounting::partials.range', ['from' => $from, 'to' => $to, 'branches' => $branches])
@if ($tb['closing_dr'] !== $tb['closing_cr'])<div class="alert err">Closing debits and credits differ by {{ $money(abs($tb['closing_dr'] - $tb['closing_cr'])) }}. This comes from unequal opening balances. <a href="{{ route('app.accounts.opening') }}">Check opening balances</a>.</div>@endif
<section class="card"><div class="table-wrap"><table><thead><tr><th>Ledger</th><th>Group</th><th class="num">Opening</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Closing</th></tr></thead><tbody>
@forelse ($tb['rows'] as $r)<tr><td><a href="{{ route('app.accounts.reports.ledger', ['ledger' => $r['ledger']->id, 'from' => $from, 'to' => $to]) }}">{{ $r['ledger']->name }}</a> <span class="small muted">{{ $r['ledger']->code }}</span></td><td class="small">{{ $r['ledger']->group->name }}</td>
    <td class="num">{{ $drcr($r['opening']) }}</td><td class="num">{{ $r['debit'] ? $money($r['debit']) : '' }}</td><td class="num">{{ $r['credit'] ? $money($r['credit']) : '' }}</td><td class="num">{{ $drcr($r['closing']) }}</td></tr>
@empty<tr><td colspan="6" class="empty">No balances yet.</td></tr>@endforelse
<tr><td colspan="2"><b>Total</b></td><td class="num small">{{ $money($tb['opening_dr']) }} Dr<br>{{ $money($tb['opening_cr']) }} Cr</td><td class="num"><b>{{ $money($tb['debit']) }}</b></td><td class="num"><b>{{ $money($tb['credit']) }}</b></td><td class="num small"><b>{{ $money($tb['closing_dr']) }} Dr<br>{{ $money($tb['closing_cr']) }} Cr</b></td></tr>
</tbody></table></div></section>
@endsection
