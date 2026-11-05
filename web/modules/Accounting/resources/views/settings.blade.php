@extends('academic::layout')
@section('title', 'Accounting settings')
@section('content')
<div class="head"><h1>Accounting settings</h1></div>
@include('accounting::partials.setup-tabs')
@php($canConfig = auth()->user()->can('academic.accounts.configure'))
<div class="cols">
<form class="card" method="post" action="{{ route('app.accounts.settings.save') }}">@csrf @method('put')
    <h2>Tax registration and approvals</h2>
    <div class="form">
        <label class="field">GSTIN<input type="text" name="gstin" value="{{ old('gstin', $s->gstin) }}" maxlength="15" style="text-transform:uppercase"></label>
        <label class="field">State code<input type="text" name="state_code" value="{{ old('state_code', $s->state_code) }}" maxlength="2"></label>
        <label class="field">TAN<input type="text" name="tan" value="{{ old('tan', $s->tan) }}" maxlength="10" style="text-transform:uppercase"></label>
        <label class="field">PAN<input type="text" name="pan" value="{{ old('pan', $s->pan) }}" maxlength="10" style="text-transform:uppercase"></label>
    </div>
    <h2 style="margin-top:16px">Approval limits (₹)</h2>
    <p class="small muted" style="margin-top:-6px">At or above the limit, a second person must approve before posting. 0 = always. Empty = never.</p>
    <div class="form">@foreach (array_keys(config('accounting.approval_thresholds')) as $type)
        <label class="field">{{ config('accounting.voucher_types.'.$type.'.label') }}<input type="text" inputmode="decimal" name="thresholds[{{ $type }}]" value="{{ ($s->approval_thresholds[$type] ?? null) === null ? '' : (\Modules\Accounting\Support\Amount::input((int) $s->approval_thresholds[$type]) ?: '0') }}"></label>
    @endforeach</div>
    <label class="check" style="margin-top:10px"><input type="hidden" name="allow_self_approval" value="0"><input type="checkbox" name="allow_self_approval" value="1" @checked($s->allow_self_approval)> Allow people to approve vouchers they entered (single-person office)</label>
    @if ($canConfig)<p><button class="btn primary" type="submit">Save</button></p>@endif
</form>
<div class="stack">
<form class="card" method="post" action="{{ route('app.accounts.settings.lock') }}">@csrf
    <h2>Lock the books</h2>
    <p class="small muted" style="margin-top:-6px">Nothing dated on or before the lock date can be posted or reversed. Lock each month after it is reviewed. Only the owner can unlock.</p>
    <div class="filters"><label class="field">Locked up to<input type="date" name="locked_until" value="{{ $s->locked_until?->format('Y-m-d') }}" max="{{ today()->format('Y-m-d') }}"></label>
        <label class="field grow">Reason (to unlock)<input type="text" name="reason"></label>@if ($canConfig)<button class="btn" type="submit">Save lock date</button>@endif</div>
</form>
<section class="card"><h2>Financial years</h2>
    @foreach ($years as $y)<p style="margin:0 0 6px"><b>{{ $y->name }}</b> · {{ $y->starts_on->format('j M Y') }} to {{ $y->ends_on->format('j M Y') }} <span class="chip {{ $y->status === 'open' ? 'present' : 'closed' }}">{{ $y->status }}</span>
        @if ($canConfig && $y->status === 'open' && $y->ends_on->isPast())<form class="inline" method="post" action="{{ route('app.accounts.years.close', $y) }}">@csrf<button class="btn sm" type="submit">Close year</button></form>@endif</p>@endforeach
    @if ($canConfig)<form method="post" action="{{ route('app.accounts.years.store') }}">@csrf<button class="btn sm" type="submit">Add next year</button></form>@endif
    @if ($opening['difference'])<p class="small" style="color:var(--bad)">Opening balances differ by {{ $money(abs($opening['difference'])) }}. <a href="{{ route('app.accounts.opening') }}">Fix them</a>.</p>@endif
</section>
</div>
</div>
<div class="cols">
<section class="card"><h2>Cost centres</h2>
    <div class="table-wrap"><table><tbody>@foreach ($costCentres as $c)<tr><td>{{ $c->code }}</td><td>{{ $c->name }}</td><td>{!! $c->is_active ? '' : '<span class="chip closed">inactive</span>' !!}</td></tr>@endforeach</tbody></table></div>
    @if ($canConfig)<form method="post" action="{{ route('app.accounts.cost-centres.store') }}" class="filters" style="margin-top:10px">@csrf
        <label class="field">Code<input type="text" name="code" required maxlength="20"></label><label class="field">Name<input type="text" name="name" required></label><button class="btn" type="submit">Add</button></form>@endif
</section>
<section class="card"><h2>GST rates</h2>
    <p class="small">@foreach ($rates as $r)<span class="chip">{{ $r->name }}</span> @endforeach</p>
    <h2 style="margin-top:14px">TDS sections</h2>
    <div class="table-wrap"><table><thead><tr><th>Section</th><th class="num">Rate</th><th class="num">No PAN</th><th class="num">Single bill</th><th class="num">Year</th><th>From</th><th>To</th></tr></thead><tbody>
    @foreach ($sections as $t)<tr><td><b>{{ $t->code }}</b> <span class="small muted">{{ $t->name }}</span></td><td class="num">{{ $t->rate_bp / 100 }}%</td><td class="num">{{ $t->rate_no_pan_bp / 100 }}%</td>
        <td class="num">{{ $t->threshold_single ? $money($t->threshold_single) : '—' }}</td><td class="num">{{ $t->threshold_annual ? $money($t->threshold_annual) : '—' }}</td><td>{{ $t->effective_from->format('j M Y') }}</td><td>{{ $t->effective_to?->format('j M Y') ?? '—' }}</td></tr>@endforeach
    </tbody></table></div>
    @if ($canConfig)<details style="margin-top:10px"><summary>New rate from a date</summary><form method="post" action="{{ route('app.accounts.tds.store') }}" class="form" style="margin-top:8px">@csrf
        <label class="field">Section<input type="text" name="code" required maxlength="10" placeholder="194J"></label><label class="field">Name<input type="text" name="name" required></label>
        <label class="field">Rate %<input type="number" name="rate" step="0.01" required></label><label class="field">Without PAN %<input type="number" name="rate_no_pan" step="0.01" value="20" required></label>
        <label class="field">Single bill limit (₹)<input type="text" name="threshold_single" inputmode="decimal"></label><label class="field">Yearly limit (₹)<input type="text" name="threshold_annual" inputmode="decimal"></label>
        <label class="field">From<input type="date" name="effective_from" required></label><div class="wide"><button class="btn" type="submit">Save rate</button></div></form></details>
    <p class="small muted">Confirm rates and limits with your chartered accountant. Old rates stay for bills dated before the change.</p>@endif
</section>
</div>
@endsection
