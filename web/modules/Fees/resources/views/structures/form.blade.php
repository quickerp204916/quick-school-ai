@extends('academic::layout')
@section('title', $s->exists ? 'Edit fee structure' : 'New fee structure')
@section('content')
@php($rows = old('items', $s->items?->map(fn ($i) => ['fee_head_id' => $i->fee_head_id, 'frequency' => $i->frequency, 'amount' => \Modules\Accounting\Support\Amount::input($i->amount), 'months' => $i->months ?? []])->all() ?? []))
@php($rows = array_pad($rows, max(5, count($rows) + 2), []))
<div class="head"><h1>{{ $s->exists ? $s->name : 'New fee structure' }}</h1><a class="btn" href="{{ route('app.fees.structures.index') }}">Back</a></div>
<form method="post" class="card" action="{{ $s->exists ? route('app.fees.structures.update', $s) : route('app.fees.structures.store') }}">@csrf @if ($s->exists) @method('put') @endif
<div class="form">
    <label class="field wide">Name<input name="name" value="{{ old('name', $s->name) }}" required maxlength="150" placeholder="Class 5 · 2026-27"></label>
    <label class="field">Session<select name="session_id">@foreach ($sessions as $x)<option value="{{ $x->id }}" @selected(old('session_id', $s->session_id) === $x->id)>{{ $x->name }}</option>@endforeach</select></label>
    <label class="field">For class<select name="grade_level_id"><option value="">Any class</option>@foreach ($levels as $l)<option value="{{ $l->id }}" @selected(old('grade_level_id', $s->grade_level_id) === $l->id)>{{ $l->name }}</option>@endforeach</select></label>
    <label class="field">Due on day of the month<input type="number" min="1" max="28" name="due_day" value="{{ old('due_day', $s->due_day) }}"></label>
</div>
<h2 style="margin-top:16px">Fee heads</h2>
<div class="table-wrap"><table><thead><tr><th>Head</th><th>Charged</th><th class="num">Amount each time (₹)</th><th>Months (monthly heads) <span class="hint">blank = all 12</span></th></tr></thead><tbody>
@foreach ($rows as $i => $row)
    <tr><td><select name="items[{{ $i }}][fee_head_id]" aria-label="Head {{ $i + 1 }}"><option value="">—</option>@foreach ($heads as $h)<option value="{{ $h->id }}" @selected(($row['fee_head_id'] ?? '') === $h->id)>{{ $h->name }}{{ $h->kind === 'optional' ? ' (optional)' : '' }}</option>@endforeach</select></td>
    <td><select name="items[{{ $i }}][frequency]" aria-label="Frequency {{ $i + 1 }}">@foreach (\Modules\Fees\Models\FeeStructureItem::FREQUENCIES as $k => $l)<option value="{{ $k }}" @selected(($row['frequency'] ?? 'monthly') === $k)>{{ $l }}</option>@endforeach</select></td>
    <td><input class="num" name="items[{{ $i }}][amount]" value="{{ $row['amount'] ?? '' }}" inputmode="decimal" aria-label="Amount {{ $i + 1 }}"></td>
    <td>@foreach ([4,5,6,7,8,9,10,11,12,1,2,3] as $m)<label class="check" style="display:inline-flex;margin-right:6px"><input type="checkbox" name="items[{{ $i }}][months][]" value="{{ $m }}" @checked(in_array($m, $row['months'] ?? []))>{{ date('M', mktime(0, 0, 0, $m, 1)) }}</label>@endforeach</td></tr>
@endforeach
</tbody></table></div>
<h2 style="margin-top:16px">Late fee</h2>
<div class="form">
    <label class="field">Rule<select name="late_fee_type">@foreach (\Modules\Fees\Models\FeeStructure::LATE_TYPES as $k => $l)<option value="{{ $k }}" @selected(old('late_fee_type', $s->late_fee_type) === $k)>{{ $l }}</option>@endforeach</select></label>
    <label class="field">Amount <span class="hint">₹, ₹ per day, or %</span><input name="late_fee_value" value="{{ old('late_fee_value', $s->late_fee_type === 'percent' ? $s->late_fee_value / 100 : \Modules\Accounting\Support\Amount::input((int) $s->late_fee_value)) }}"></label>
    <label class="field">Grace days<input type="number" min="0" name="grace_days" value="{{ old('grace_days', $s->grace_days ?? 0) }}"></label>
    <label class="field">Maximum late fee (₹) <span class="hint">optional</span><input name="late_fee_cap" value="{{ old('late_fee_cap', $s->late_fee_cap ? \Modules\Accounting\Support\Amount::input($s->late_fee_cap) : '') }}"></label>
    <input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $s->exists ? $s->is_active : true))> Active</label>
</div>
<div class="toolbar" style="margin-top:14px"><button class="btn primary" type="submit">Save structure</button></div>
</form>
@if ($preview)
<section class="card"><h2>Instalments a full-year student gets</h2><div class="table-wrap"><table><thead><tr><th>#</th><th>Period</th><th>Issued</th><th>Due</th><th>Heads</th><th class="num">Amount</th></tr></thead><tbody>
@foreach ($preview as $n => $p)<tr><td>{{ $n }}</td><td>{{ $p['period'] }}</td><td>{{ \Carbon\Carbon::parse($p['issue'])->format('j M Y') }}</td><td>{{ \Carbon\Carbon::parse($p['due'])->format('j M Y') }}</td>
<td>{{ collect($p['lines'])->pluck('description')->join(', ') }}</td><td class="num">{{ $money(collect($p['lines'])->sum('amount')) }}</td></tr>@endforeach
<tr><td colspan="5"><b>Year</b></td><td class="num"><b>{{ $money(collect($preview)->sum(fn ($p) => collect($p['lines'])->sum('amount'))) }}</b></td></tr>
</tbody></table></div><p class="small muted">Optional heads are included here; students who don't take them are not charged.</p></section>
@endif
@endsection
