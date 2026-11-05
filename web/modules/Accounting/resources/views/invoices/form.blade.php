@extends('academic::layout')
@php($label = ['sales' => 'Sales invoice', 'purchase' => 'Purchase invoice', 'credit_note' => 'Credit note', 'debit_note' => 'Debit note', 'expense' => 'Expense'][$kind])
@section('title', 'New '.strtolower($label))
@section('content')
@php($sales = in_array($kind, ['sales', 'credit_note'], true))
@php($rows = array_pad(old('lines', []), 5, []))
<div class="head"><div><h1>New {{ strtolower($label) }}</h1>
    <p class="muted" style="margin:4px 0 0">GST: CGST and SGST in your state, IGST for other states. @unless ($sales)TDS is worked out from the vendor’s TDS section and this year’s bills.@endunless</p></div>
    <a class="btn" href="{{ route('app.accounts.invoices.index', ['kind' => $kind]) }}">Cancel</a></div>
<form class="card" method="post" action="{{ route('app.accounts.invoices.store') }}">@csrf
    <input type="hidden" name="kind" value="{{ $kind }}">
    <div class="form">
        <label class="field">{{ $sales ? 'Customer' : 'Vendor' }}<select name="party_ledger_id" required><option value="">Choose</option>@foreach ($parties as $p)<option value="{{ $p->id }}" @selected(old('party_ledger_id') === $p->id)>{{ $p->name }}{{ $p->gstin ? ' · '.$p->gstin : '' }}</option>@endforeach</select>
            @if ($parties->isEmpty())<span class="small">No {{ $sales ? 'customer' : 'vendor' }} ledgers yet. <a href="{{ route('app.accounts.chart') }}">Add one</a>.</span>@endif</label>
        <label class="field">Date<input type="date" name="date" value="{{ old('date', today()->format('Y-m-d')) }}" required></label>
        <label class="field">Due date<input type="date" name="due_date" value="{{ old('due_date') }}"></label>
        @if ($kind === 'purchase' || $kind === 'expense')<label class="field">Supplier’s bill number<input type="text" name="party_invoice_no" value="{{ old('party_invoice_no') }}" maxlength="40" @if ($kind === 'purchase') required @endif></label>@endif
        @if (in_array($kind, ['credit_note', 'debit_note'], true))<label class="field">Against<select name="against_invoice_id" required>@foreach ($againstList as $a)<option value="{{ $a->id }}" @selected(old('against_invoice_id', $inv->against_invoice_id) === $a->id)>{{ $a->number }} · {{ $a->party?->name }} · {{ $money($a->total) }}</option>@endforeach</select></label>@endif
        <label class="field">Place of supply <span class="hint">state code; default from the party</span><input type="text" name="place_of_supply" value="{{ old('place_of_supply') }}" maxlength="2" inputmode="numeric"></label>
        @if ($branches->count() > 1)<label class="field">Branch<select name="branch_id"><option value="">—</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></label>@endif
    </div>
    <div class="table-wrap" style="margin-top:14px"><table><thead><tr><th>Ledger</th><th>Description</th><th>HSN/SAC</th><th class="num">Qty</th><th class="num">Rate (₹)</th><th>GST</th><th>Cost centre</th></tr></thead><tbody>
    @foreach ($rows as $i => $r)<tr>
        <td><select name="lines[{{ $i }}][ledger_id]" aria-label="Ledger, line {{ $i + 1 }}"><option value="">—</option>@foreach ($itemLedgers as $l)<option value="{{ $l->id }}" @selected(($r['ledger_id'] ?? '') === $l->id)>{{ $l->name }}</option>@endforeach</select></td>
        <td><input type="text" name="lines[{{ $i }}][description]" value="{{ $r['description'] ?? '' }}" maxlength="200" aria-label="Description"></td>
        <td><input type="text" name="lines[{{ $i }}][hsn_sac]" value="{{ $r['hsn_sac'] ?? '' }}" maxlength="10" style="width:90px" aria-label="HSN or SAC"></td>
        <td><input class="mark" type="text" inputmode="decimal" name="lines[{{ $i }}][quantity]" value="{{ $r['quantity'] ?? 1 }}" style="width:70px" aria-label="Quantity"></td>
        <td><input class="mark" type="text" inputmode="decimal" name="lines[{{ $i }}][rate]" value="{{ $r['rate'] ?? '' }}" aria-label="Rate"></td>
        <td><select name="lines[{{ $i }}][gst_bp]" aria-label="GST rate">@foreach (config('accounting.gst_rates') as [$n, $bp])<option value="{{ $bp }}" @selected((int) ($r['gst_bp'] ?? 0) === $bp)>{{ $n }}</option>@endforeach</select></td>
        <td><select name="lines[{{ $i }}][cost_centre_id]" aria-label="Cost centre"><option value="">—</option>@foreach ($costCentres as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></td></tr>@endforeach
    </tbody></table></div>
    <label class="field" style="margin-top:12px">Notes<input type="text" name="notes" value="{{ old('notes') }}" maxlength="500"></label>
    <div class="toolbar" style="margin-top:12px"><button class="btn primary" type="submit" name="action" value="submit">Save and post</button><button class="btn" type="submit" name="action" value="draft">Save as draft</button></div>
</form>
@endsection
