@extends('platform::layout')
@section('title', $invoice->number)
@section('content')
@php($M = fn ($v) => \App\Core\Support\Money::of($v)->format())
<div class="head"><div><h1>Tax invoice {{ $invoice->number }}</h1><p class="muted" style="margin:4px 0 0"><span class="chip {{ $invoice->displayStatus() }}">{{ $invoice->displayStatus() }}</span> · balance {{ $invoice->balance()->format() }}</p></div>
    <a class="btn" href="{{ route('platform.tenants.show', $invoice->tenant) }}">Institution</a></div>

<section class="card">
    <div class="cols">
        <div><h2>From</h2><p style="margin:6px 0 0">{{ $seller['name'] }}<br><span class="small muted">{{ $seller['address'] }}</span><br>GSTIN: {{ $invoice->seller_gstin ?: 'not set (configure PLATFORM_SELLER_GSTIN)' }}</p></div>
        <div><h2>Billed to</h2><p style="margin:6px 0 0">{{ $invoice->buyer_name }}<br>GSTIN: {{ $invoice->buyer_gstin ?: 'Unregistered' }}<br>Place of supply: {{ $invoice->place_of_supply }} · {{ $placeOfSupply }}</p></div>
        <div><dl class="kv"><dt>Issued</dt><dd>{{ $invoice->issue_date->format('j M Y') }}</dd><dt>Due</dt><dd>{{ $invoice->due_date->format('j M Y') }}</dd><dt>SAC</dt><dd>{{ $invoice->sac }}</dd></dl></div>
    </div>
    <div class="table-wrap" style="margin-top:16px"><table>
        <thead><tr><th>Description</th><th class="num">Amount</th></tr></thead>
        <tbody>
            <tr><td>{{ $invoice->description }}</td><td class="num">{{ $M($invoice->subtotal) }}</td></tr>
            @if (\App\Core\Support\Money::of($invoice->igst)->isPositive())
                <tr><td>IGST {{ config('platform.billing.gst_rate_bp') / 100 }}%</td><td class="num">{{ $M($invoice->igst) }}</td></tr>
            @else
                <tr><td>CGST {{ config('platform.billing.gst_rate_bp') / 200 }}%</td><td class="num">{{ $M($invoice->cgst) }}</td></tr>
                <tr><td>SGST {{ config('platform.billing.gst_rate_bp') / 200 }}%</td><td class="num">{{ $M($invoice->sgst) }}</td></tr>
            @endif
            <tr><td><b>Total</b></td><td class="num"><b>{{ $M($invoice->total) }}</b></td></tr>
            <tr><td>Paid</td><td class="num">{{ $M($invoice->amount_paid) }}</td></tr>
        </tbody>
    </table></div>
</section>

<div class="cols">
    <section class="card"><h2>Payments</h2>
        <div class="table-wrap"><table><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead><tbody>
        @forelse ($invoice->payments as $p)
            <tr><td>{{ $p->paid_on->format('j M Y') }}</td><td>{{ str_replace('_', ' ', $p->method) }}</td><td>{{ $p->reference }}</td><td class="num">{{ $M($p->amount) }}</td></tr>
        @empty <tr><td colspan="4" class="muted">No payments yet.</td></tr> @endforelse
        </tbody></table></div>
    </section>
    @if ($invoice->status === 'open')
    <section class="card"><h2>Record a payment</h2>
        <form method="post" action="{{ route('platform.invoices.payments.store', $invoice) }}" class="form">@csrf
            <input type="hidden" name="form_token" value="{{ \Illuminate\Support\Str::uuid() }}">
            <label class="field">Amount (₹)<input type="text" name="amount" value="{{ $invoice->balance()->toDecimal() }}" required inputmode="decimal"></label>
            <label class="field">Received on<input type="date" name="paid_on" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
            <label class="field">Method<select name="method">@foreach ($methods as $m)<option value="{{ $m }}">{{ ucfirst(str_replace('_', ' ', $m)) }}</option>@endforeach</select></label>
            <label class="field">Reference<span class="hint">UTR, cheque or gateway ID</span><input type="text" name="reference" maxlength="100"></label>
            <div class="wide"><button class="btn primary" type="submit">Record payment</button></div>
        </form>
        @if (\App\Core\Support\Money::of($invoice->amount_paid)->isZero())
        <details style="margin-top:14px"><summary class="small">Void this invoice</summary>
            <form method="post" action="{{ route('platform.invoices.void', $invoice) }}" class="filters" style="margin-top:8px">@csrf
                <label class="field grow">Reason<input type="text" name="reason" required minlength="5" maxlength="300"></label>
                <button class="btn danger" type="submit">Void</button></form></details>
        @endif
    </section>
    @endif
</div>
@endsection
