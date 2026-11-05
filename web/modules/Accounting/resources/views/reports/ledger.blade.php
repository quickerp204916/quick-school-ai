@extends('academic::layout')
@section('title', $title)
@section('content')
<div class="head"><div><h1>{{ $title }}{{ $ledger ? ' · '.$ledger->name : '' }}</h1><p class="muted" style="margin:4px 0 0">{{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}</p></div>
    @if ($st)<a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv', 'ledger' => $ledger->id]) }}">Download CSV</a>@endif</div>
@include('accounting::partials.report-tabs')
@component('accounting::partials.range', ['from' => $from, 'to' => $to, 'branches' => $branches])
    <label class="field">Ledger<select name="ledger">@foreach ($choices as $c)<option value="{{ $c->id }}" @selected($ledger?->id === $c->id)>{{ $c->name }}</option>@endforeach</select></label>
@endcomponent
@if (! $st)<div class="card empty">No {{ strtolower($title) }} ledgers yet.</div>@else
<section class="card"><div class="table-wrap"><table><thead><tr><th>Date</th><th>Voucher</th><th>Particulars</th><th>Narration</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody>
    <tr><td></td><td></td><td><b>Opening balance</b></td><td></td><td></td><td></td><td class="num"><b>{{ $drcr($st['opening']) }}</b></td></tr>
    @foreach ($st['rows'] as $r)<tr><td>{{ $r['entry']->date->format('j M Y') }}</td><td><a href="{{ route('app.accounts.vouchers.show', $r['voucher']) }}">{{ $r['voucher']->number }}</a><div class="small muted">{{ $r['voucher']->typeLabel() }}</div></td>
        <td>{{ $r['against'] }}</td><td class="small">{{ \Illuminate\Support\Str::limit($r['entry']->narration ?? $r['voucher']->narration, 60) }}{{ $r['entry']->bill_ref ? ' · bill '.$r['entry']->bill_ref : '' }}</td>
        <td class="num">{{ $r['entry']->debit ? $money($r['entry']->debit) : '' }}</td><td class="num">{{ $r['entry']->credit ? $money($r['entry']->credit) : '' }}</td><td class="num">{{ $drcr($r['balance']) }}</td></tr>@endforeach
    <tr><td colspan="4"><b>Totals and closing balance</b></td><td class="num"><b>{{ $money($st['debit']) }}</b></td><td class="num"><b>{{ $money($st['credit']) }}</b></td><td class="num"><b>{{ $drcr($st['closing']) }}</b></td></tr>
</tbody></table></div></section>@endif
@endsection
