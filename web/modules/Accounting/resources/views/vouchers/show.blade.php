@extends('academic::layout')
@section('title', $v->number ?? 'Draft voucher')
@section('content')
@php($lines = $v->isPosted() ? $v->entries->map(fn ($e) => ['ledger' => $e->ledger->name, 'ledger_id' => $e->ledger_id, 'debit' => $e->debit, 'credit' => $e->credit, 'cc' => $e->costCentre?->name, 'bill' => $e->bill_ref, 'narration' => $e->narration])
    : collect($v->lines)->map(fn ($l) => ['ledger' => $ledgers[$l['ledger_id']] ?? '?', 'ledger_id' => $l['ledger_id'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'cc' => $costCentres[$l['cost_centre_id'] ?? ''] ?? null, 'bill' => $l['bill_ref'] ?? null, 'narration' => $l['narration'] ?? null]))
<div class="head"><div><h1>{{ $v->typeLabel() }} {{ $v->number }} <span class="chip {{ ['posted' => 'present', 'pending' => 'warning', 'rejected' => 'absent', 'reversed' => 'closed', 'draft' => 'draft'][$v->status] }}">{{ $v->status }}</span></h1>
    <p class="muted" style="margin:4px 0 0">{{ $v->date->format('l, j F Y') }} · {{ $money($v->total) }}{{ $v->reference ? ' · Ref '.$v->reference : '' }}{{ $v->source_type ? ' · from '.str_replace('_', ' ', $v->source_type) : '' }}</p></div>
    <div class="toolbar">
        @if (in_array($v->status, ['draft', 'rejected'], true) && ! $v->source_type)@can('academic.accounts.post')
            <a class="btn" href="{{ route('app.accounts.vouchers.edit', $v) }}">Edit</a>
            <form class="inline" method="post" action="{{ route('app.accounts.vouchers.submit', $v) }}">@csrf<button class="btn primary" type="submit">{{ $needsApproval ? 'Send for approval' : 'Post' }}</button></form>
            <form class="inline" method="post" action="{{ route('app.accounts.vouchers.destroy', $v) }}">@csrf @method('delete')<button class="btn danger" type="submit">Delete draft</button></form>@endcan @endif
        @if ($v->status === 'pending')@can('academic.accounts.approve')
            <form class="inline" method="post" action="{{ route('app.accounts.vouchers.approve', $v) }}">@csrf<button class="btn primary" type="submit">Approve and post</button></form>@endcan @endif
        <a class="btn" href="{{ route('app.accounts.vouchers.index') }}">All vouchers</a>
    </div></div>
@if ($v->status === 'rejected')<div class="alert err">Rejected: {{ $v->rejected_reason }}. Edit it and send it again.</div>@endif
@if ($v->reversedBy)<div class="alert err">Reversed by <a href="{{ route('app.accounts.vouchers.show', $v->reversedBy) }}">{{ $v->reversedBy->number }}</a>. Both stay in the books and cancel each other out.</div>@endif
@if ($v->reversalOf)<div class="alert ok">This reverses <a href="{{ route('app.accounts.vouchers.show', $v->reversalOf) }}">{{ $v->reversalOf->number }}</a>.</div>@endif
<section class="card">
    <p style="margin:0 0 10px">{{ $v->narration }}</p>
    <div class="table-wrap"><table><thead><tr><th>Ledger</th><th class="num">Debit</th><th class="num">Credit</th><th>Cost centre</th><th>Bill ref</th><th>Narration</th></tr></thead><tbody>
    @foreach ($lines as $l)<tr><td>{{ $l['ledger'] }}</td><td class="num">{{ $l['debit'] ? $money($l['debit']) : '' }}</td><td class="num">{{ $l['credit'] ? $money($l['credit']) : '' }}</td><td>{{ $l['cc'] }}</td><td>{{ $l['bill'] }}</td><td class="small">{{ $l['narration'] }}</td></tr>@endforeach
    <tr><td><b>Total</b></td><td class="num"><b>{{ $money($lines->sum('debit')) }}</b></td><td class="num"><b>{{ $money($lines->sum('credit')) }}</b></td><td colspan="3"></td></tr>
    </tbody></table></div>
    <dl class="kv small" style="margin-top:12px">
        <dt>Entered by</dt><dd>{{ $users[$v->created_by] ?? 'System' }} · {{ $v->created_at->format('j M Y, g:i a') }}</dd>
        @if ($v->approved_by)<dt>Approved by</dt><dd>{{ $users[$v->approved_by] ?? '—' }} · {{ $v->approved_at?->format('j M Y, g:i a') }}</dd>@endif
        @if ($v->posted_at)<dt>Posted</dt><dd>{{ $v->posted_at->format('j M Y, g:i a') }}</dd><dt>Fingerprint</dt><dd class="mono" style="font-family:ui-monospace,monospace;font-size:12px">{{ $v->entries_hash }}</dd>@endif
    </dl>
</section>
@if ($v->status === 'posted' && $v->type !== 'reversal')@can('academic.accounts.approve')
<form class="card danger-zone" method="post" action="{{ route('app.accounts.vouchers.reverse', $v) }}">@csrf
    <h2>Reverse this voucher</h2>
    <p class="small muted" style="margin-top:-6px">Posts a new voucher with every line on the other side. The original stays in the books.</p>
    <div class="filters"><label class="field grow">Reason<input type="text" name="reason" required minlength="5" placeholder="Paid from the wrong bank account"></label>
        <label class="field">Reversal date<input type="date" name="date" value="{{ today()->format('Y-m-d') }}"></label><button class="btn danger" type="submit">Reverse</button></div>
</form>@endcan @endif
@endsection
