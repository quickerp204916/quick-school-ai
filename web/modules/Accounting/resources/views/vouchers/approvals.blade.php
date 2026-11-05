@extends('academic::layout')
@section('title', 'Approvals')
@section('content')
<div class="head"><div><h1>Waiting for approval</h1><p class="muted" style="margin:4px 0 0">Vouchers at or above the approval limit. The person who entered a voucher cannot approve it.</p></div></div>
@forelse ($list as $v)
<section class="card">
    <div class="head"><h2><a href="{{ route('app.accounts.vouchers.show', $v) }}">{{ $v->typeLabel() }}</a> · {{ $money($v->total) }} <span class="small muted">· {{ $v->date->format('j M Y') }} · sent {{ $v->submitted_at?->diffForHumans() }}</span></h2>
        @can('academic.accounts.approve')<div class="toolbar">
            <form class="inline" method="post" action="{{ route('app.accounts.vouchers.approve', $v) }}">@csrf<button class="btn primary" type="submit">Approve and post</button></form>
            <details><summary class="btn">Reject</summary><form method="post" action="{{ route('app.accounts.vouchers.reject', $v) }}" class="filters" style="margin-top:8px">@csrf
                <label class="field grow">Reason<input type="text" name="reason" required minlength="5"></label><button class="btn danger" type="submit">Reject</button></form></details></div>@endcan</div>
    <p class="small" style="margin:0 0 8px">{{ $v->narration }}</p>
    <div class="table-wrap"><table><tbody>@foreach ($v->lines as $l)<tr><td>{{ $ledgers[$l['ledger_id']] ?? '?' }}</td><td class="num">{{ $l['debit'] ? $money($l['debit']).' Dr' : '' }}</td><td class="num">{{ $l['credit'] ? $money($l['credit']).' Cr' : '' }}</td></tr>@endforeach</tbody></table></div>
</section>
@empty<div class="card empty">Nothing is waiting for approval.</div>@endforelse
@endsection
