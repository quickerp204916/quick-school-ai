@extends('academic::layout')
@section('title', 'Vouchers')
@section('content')
<div class="head"><div><h1>Vouchers</h1><p class="muted" style="margin:4px 0 0">Every posted voucher is permanent. To correct one, open it and reverse it.</p></div>
    <div class="toolbar"><a class="btn" href="{{ route('app.accounts.approvals') }}">Waiting for approval</a>@can('academic.accounts.post')<a class="btn primary" href="{{ route('app.accounts.vouchers.create') }}">New voucher</a>@endcan</div></div>
@component('accounting::partials.range', ['from' => $from, 'to' => $to, 'branches' => $branches])
    <label class="field">Type<select name="type"><option value="">All</option>@foreach (config('accounting.voucher_types') as $k => $t)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $t['label'] }}</option>@endforeach</select></label>
    <label class="field">Status<select name="status"><option value="">All</option>@foreach (\Modules\Accounting\Models\Voucher::STATUSES as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>@endforeach</select></label>
    <label class="field grow">Search<input type="search" name="q" value="{{ request('q') }}" placeholder="Number, narration or reference"></label>
@endcomponent
<section class="card"><div class="table-wrap"><table><thead><tr><th>Date</th><th>Number</th><th>Type</th><th>Narration</th><th>Reference</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
@forelse ($list as $v)
    <tr><td>{{ $v->date->format('j M Y') }}</td><td><a href="{{ route('app.accounts.vouchers.show', $v) }}">{{ $v->number ?? 'Draft' }}</a></td><td>{{ $v->typeLabel() }}</td>
        <td>{{ \Illuminate\Support\Str::limit($v->narration, 60) }} @if ($v->source_type)<span class="chip">{{ str_replace('_', ' ', $v->source_type) }}</span>@endif</td><td>{{ $v->reference }}</td>
        <td class="num">{{ $money($v->total) }}</td><td><span class="chip {{ ['posted' => 'present', 'pending' => 'warning', 'rejected' => 'absent', 'reversed' => 'closed', 'draft' => 'draft'][$v->status] }}">{{ $v->status }}</span></td></tr>
@empty<tr><td colspan="7" class="empty">No vouchers in this period.</td></tr>@endforelse
</tbody></table></div>@include('academic::partials.pager', ['p' => $list])</section>
@endsection
