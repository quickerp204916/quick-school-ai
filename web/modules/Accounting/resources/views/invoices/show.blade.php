@extends('academic::layout')
@section('title', ($inv->number ?? 'Draft').' · '.$inv->kindLabel())
@section('content')
<div class="head"><div><h1>{{ $inv->kindLabel() }} {{ $inv->number }} <span class="chip {{ ['posted' => 'present', 'pending' => 'warning', 'cancelled' => 'closed', 'draft' => 'draft'][$inv->status] }}">{{ $inv->status }}</span></h1>
    <p class="muted" style="margin:4px 0 0">{{ $inv->party->name }} · {{ $inv->date->format('j M Y') }}{{ $inv->due_date ? ' · due '.$inv->due_date->format('j M Y') : '' }}{{ $inv->party_invoice_no ? ' · their bill '.$inv->party_invoice_no : '' }}</p></div>
    <div class="toolbar">
        @if ($inv->status === 'draft')@can('academic.accounts.post')<form class="inline" method="post" action="{{ route('app.accounts.invoices.submit', $inv) }}">@csrf<button class="btn primary" type="submit">Post</button></form>
            <form class="inline" method="post" action="{{ route('app.accounts.invoices.destroy', $inv) }}">@csrf @method('delete')<button class="btn danger" type="submit">Delete draft</button></form>@endcan @endif
        @if ($inv->voucher)<a class="btn" href="{{ route('app.accounts.vouchers.show', $inv->voucher) }}">Voucher {{ $inv->voucher->number ?? '(pending)' }}</a>@endif
        @if ($inv->status === 'posted' && in_array($inv->kind, ['sales', 'purchase', 'expense'], true))<a class="btn" href="{{ route('app.accounts.invoices.create', ['kind' => $inv->kind === 'sales' ? 'credit_note' : 'debit_note', 'against' => $inv->id]) }}">{{ $inv->kind === 'sales' ? 'Credit note' : 'Debit note' }}</a>@endif
    </div></div>
@if ($inv->status === 'pending')<div class="alert err" style="background:var(--warn-bg);color:var(--warn)">Waiting for approval. It gets its number when the voucher is approved.</div>@endif
<section class="card"><div class="table-wrap"><table><thead><tr><th>Ledger</th><th>Description</th><th>HSN/SAC</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">GST %</th><th class="num">Amount</th></tr></thead><tbody>
    @foreach ($inv->lines as $l)<tr><td>{{ $l->ledger->name }}</td><td>{{ $l->description }}</td><td>{{ $l->hsn_sac }}</td><td class="num">{{ rtrim(rtrim($l->quantity, '0'), '.') }}</td><td class="num">{{ $money($l->rate) }}</td><td class="num">{{ $l->gst_bp / 100 }}</td><td class="num">{{ $money($l->amount) }}</td></tr>@endforeach
    <tr><td colspan="6" class="num">Taxable value</td><td class="num">{{ $money($inv->subtotal) }}</td></tr>
    @if ($inv->cgst)<tr><td colspan="6" class="num">CGST</td><td class="num">{{ $money($inv->cgst) }}</td></tr><tr><td colspan="6" class="num">SGST</td><td class="num">{{ $money($inv->sgst) }}</td></tr>@endif
    @if ($inv->igst)<tr><td colspan="6" class="num">IGST (place of supply {{ $inv->place_of_supply }})</td><td class="num">{{ $money($inv->igst) }}</td></tr>@endif
    <tr><td colspan="6" class="num"><b>Total</b></td><td class="num"><b>{{ $money($inv->total) }}</b></td></tr>
    @if ($inv->tds)<tr><td colspan="6" class="num">Less TDS u/s {{ $inv->tdsSection?->code }}</td><td class="num">{{ $money($inv->tds) }}</td></tr><tr><td colspan="6" class="num"><b>Payable to vendor</b></td><td class="num"><b>{{ $money($inv->net) }}</b></td></tr>@endif
    @if ($inv->status === 'posted' && ! in_array($inv->kind, ['credit_note', 'debit_note'], true))<tr><td colspan="6" class="num">Still due</td><td class="num"><b>{{ $money($due) }}</b></td></tr>@endif
</tbody></table></div>
@if ($inv->notes)<p class="small">{{ $inv->notes }}</p>@endif
@if ($inv->against)<p class="small">Adjusts <a href="{{ route('app.accounts.invoices.show', $inv->against) }}">{{ $inv->against->number }}</a>.</p>@endif
@foreach ($notes as $n)<p class="small"><a href="{{ route('app.accounts.invoices.show', $n) }}">{{ $n->kindLabel() }} {{ $n->number ?? '(draft)' }}</a> · {{ $money($n->total) }}</p>@endforeach
<p class="small muted">To record payment, enter a {{ $inv->isSalesSide() ? 'receipt' : 'payment' }} voucher and put <b>{{ $inv->number }}</b> in the bill ref of the party line.</p>
</section>
@endsection
