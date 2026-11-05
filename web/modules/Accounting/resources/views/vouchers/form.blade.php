@extends('academic::layout')
@section('title', ($v->exists ? 'Edit ' : 'New ').strtolower(config('accounting.voucher_types.'.$v->type.'.label')))
@section('content')
@php($hint = ['payment' => 'Money paid out: debit the expense or party, credit cash or bank.', 'receipt' => 'Money received: debit cash or bank, credit the income or party.',
    'contra' => 'Money moved between cash and bank, for example a cash deposit into the bank.', 'journal' => 'Adjustments that do not touch cash or bank.'][$v->type])
@php($rows = old('lines', collect($v->lines)->map(fn ($l) => ['ledger_id' => $l['ledger_id'], 'debit' => \Modules\Accounting\Support\Amount::input($l['debit']), 'credit' => \Modules\Accounting\Support\Amount::input($l['credit']),
    'cost_centre_id' => $l['cost_centre_id'] ?? '', 'narration' => $l['narration'] ?? '', 'bill_ref' => $l['bill_ref'] ?? ''])->all()))
@php($rows = array_pad($rows, max(6, count($rows) + 2), []))
<div class="head"><div><h1>{{ $v->exists ? 'Edit' : 'New' }} voucher</h1><p class="muted" style="margin:4px 0 0">{{ $hint }}</p></div>
    <a class="btn" href="{{ $v->exists ? route('app.accounts.vouchers.show', $v) : route('app.accounts.vouchers.index') }}">Cancel</a></div>
@unless ($v->exists)
<nav class="toolbar">@foreach (config('accounting.manual_types') as $k)<a class="btn sm {{ $v->type === $k ? 'primary' : '' }}" href="{{ route('app.accounts.vouchers.create', ['type' => $k]) }}">{{ config('accounting.voucher_types.'.$k.'.label') }}</a>@endforeach</nav>
@endunless
<form class="card" method="post" action="{{ $v->exists ? route('app.accounts.vouchers.update', $v) : route('app.accounts.vouchers.store') }}" id="voucher-form">@csrf @if ($v->exists) @method('put') @endif
    <input type="hidden" name="type" value="{{ $v->type }}">
    <div class="form">
        <label class="field">Date<input type="date" name="date" value="{{ old('date', $v->date?->format('Y-m-d')) }}" required></label>
        <label class="field">Reference <span class="hint">cheque, UTR or bill number</span><input type="text" name="reference" value="{{ old('reference', $v->reference) }}" maxlength="60"></label>
        @if ($branches->count() > 1)<label class="field">Branch<select name="branch_id"><option value="">—</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $v->branch_id) === $b->id)>{{ $b->name }}</option>@endforeach</select></label>@endif
        <label class="field wide">Narration<input type="text" name="narration" value="{{ old('narration', $v->narration) }}" maxlength="500" placeholder="Electricity bill for October, BSES account 12345"></label>
    </div>
    <div class="table-wrap" style="margin-top:14px"><table><thead><tr><th>Ledger</th><th class="num">Debit (₹)</th><th class="num">Credit (₹)</th><th>Cost centre</th><th>Bill ref</th><th>Line narration</th></tr></thead><tbody>
    @foreach ($rows as $i => $r)
        <tr>
            <td style="min-width:240px"><select name="lines[{{ $i }}][ledger_id]" aria-label="Ledger, line {{ $i + 1 }}"><option value="">—</option>
                @foreach ($ledgers->groupBy(fn ($l) => $l->group->name) as $g => $ls)<optgroup label="{{ $g }}">@foreach ($ls as $l)<option value="{{ $l->id }}" @selected(($r['ledger_id'] ?? '') === $l->id)>{{ $l->name }}</option>@endforeach</optgroup>@endforeach</select></td>
            <td><input class="mark js-dr" type="text" inputmode="decimal" name="lines[{{ $i }}][debit]" value="{{ $r['debit'] ?? '' }}" aria-label="Debit, line {{ $i + 1 }}"></td>
            <td><input class="mark js-cr" type="text" inputmode="decimal" name="lines[{{ $i }}][credit]" value="{{ $r['credit'] ?? '' }}" aria-label="Credit, line {{ $i + 1 }}"></td>
            <td><select name="lines[{{ $i }}][cost_centre_id]" aria-label="Cost centre"><option value="">—</option>@foreach ($costCentres as $c)<option value="{{ $c->id }}" @selected(($r['cost_centre_id'] ?? '') === $c->id)>{{ $c->name }}</option>@endforeach</select></td>
            <td><input type="text" name="lines[{{ $i }}][bill_ref]" value="{{ $r['bill_ref'] ?? '' }}" maxlength="40" style="width:130px" aria-label="Bill reference"></td>
            <td><input type="text" name="lines[{{ $i }}][narration]" value="{{ $r['narration'] ?? '' }}" maxlength="300" aria-label="Line narration"></td>
        </tr>
    @endforeach
    <tr><td><b>Total</b></td><td class="num"><b id="t-dr">—</b></td><td class="num"><b id="t-cr">—</b></td><td colspan="3" id="t-diff" class="small"></td></tr>
    </tbody></table></div>
    <p class="small muted">Bill ref: the invoice number this payment or receipt settles, so outstanding bills stay correct.</p>
    <div class="toolbar"><button class="btn primary" type="submit" name="action" value="submit">Save and post</button><button class="btn" type="submit" name="action" value="draft">Save as draft</button>
        <span class="small muted">Above the approval limit it goes for approval instead of posting.</span></div>
</form>
<script>
(function () {
  const f = document.getElementById('voucher-form'), num = v => { const n = parseFloat(String(v).replace(/,/g, '')); return isNaN(n) ? 0 : Math.round(n * 100); };
  const fmt = p => (p / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  function sum() { let d = 0, c = 0; f.querySelectorAll('.js-dr').forEach(i => d += num(i.value)); f.querySelectorAll('.js-cr').forEach(i => c += num(i.value));
    document.getElementById('t-dr').textContent = fmt(d); document.getElementById('t-cr').textContent = fmt(c);
    const t = document.getElementById('t-diff'); t.textContent = d === c ? (d ? 'Balanced' : '') : 'Difference ₹' + fmt(Math.abs(d - c)); t.style.color = d === c ? 'var(--good)' : 'var(--bad)'; }
  f.addEventListener('input', sum); sum();
})();
</script>
@endsection
