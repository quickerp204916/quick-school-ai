@extends('academic::layout')
@section('title', 'Day book')
@section('content')
<div class="head"><div><h1>Day book</h1><p class="muted" style="margin:4px 0 0">Every posted voucher with its entries.</p></div></div>
@include('accounting::partials.report-tabs')
@component('accounting::partials.range', ['from' => $from, 'to' => $to, 'branches' => $branches])
    <label class="field">Type<select name="type"><option value="">All</option>@foreach (config('accounting.voucher_types') as $k => $t)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $t['label'] }}</option>@endforeach</select></label>
@endcomponent
<section class="card"><div class="table-wrap"><table><thead><tr><th>Date</th><th>Voucher</th><th>Ledger</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
@forelse ($list as $v)@foreach ($v->entries as $i => $e)<tr>
    <td>{{ $i ? '' : $v->date->format('j M Y') }}</td><td>@unless ($i)<a href="{{ route('app.accounts.vouchers.show', $v) }}">{{ $v->number }}</a> @if ($v->status === 'reversed')<span class="chip closed">reversed</span>@endif<div class="small muted">{{ \Illuminate\Support\Str::limit($v->narration, 50) }}</div>@endunless</td>
    <td style="{{ $e->credit ? 'padding-left:28px' : '' }}">{{ $e->ledger->name }}</td><td class="num">{{ $e->debit ? $money($e->debit) : '' }}</td><td class="num">{{ $e->credit ? $money($e->credit) : '' }}</td></tr>@endforeach
@empty<tr><td colspan="5" class="empty">No vouchers in this period.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
