@extends('academic::layout')
@section('title', 'Fees · '.$student->fullName())
@section('content')
@php($open = $invoices->whereIn('status', ['open', 'partial']))
@php($balance = $open->sum(fn ($i) => $i->balance()))
<div class="head"><div><h1>{{ $student->fullName() }}</h1><p class="muted" style="margin:4px 0 0">{{ $student->admission_no }} · {{ $student->currentEnrollment?->section?->label() }} · {{ $student->primaryGuardian()?->name }} {{ $student->primaryGuardian()?->phone }}</p></div>
    <a class="btn" href="{{ route('app.fees.students.index') }}">All students</a></div>
<div class="grid">
    <div class="card kpi"><div class="label">Due now</div><div class="value">{{ $money($balance) }}</div><div class="small muted">{{ $open->count() }} open invoice(s)</div></div>
    <div class="card kpi"><div class="label">Overdue</div><div class="value">{{ $money($open->filter(fn ($i) => $i->isOverdue())->sum(fn ($i) => $i->balance())) }}</div></div>
    <div class="card kpi"><div class="label">Paid this session</div><div class="value">{{ $money($payments->where('status', 'success')->sum('amount') - $payments->sum('refunded')) }}</div></div>
    <div class="card kpi"><div class="label">Advance held</div><div class="value">{{ $money($payments->where('status', 'success')->sum('advance')) }}</div></div>
</div>

@can('academic.fees.collect')
@if ($open->isNotEmpty() || $plan)
<section class="card"><h2>Collect a payment</h2>
<form method="post" action="{{ route('app.fees.students.collect', $student) }}" class="form">@csrf
    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', 'web:'.$token) }}">
    <label class="field">Amount (₹)<input name="amount" value="{{ old('amount', \Modules\Accounting\Support\Amount::input($balance)) }}" inputmode="decimal" required></label>
    <label class="field">Date<input type="date" name="date" value="{{ old('date', today()->format('Y-m-d')) }}" max="{{ today()->format('Y-m-d') }}"></label>
    <label class="field">Mode<select name="mode">@foreach (['cash', 'upi', 'cheque', 'bank'] as $m)<option value="{{ $m }}" @selected(old('mode') === $m)>{{ \Modules\Fees\Models\FeePayment::MODES[$m] }}</option>@endforeach</select></label>
    <label class="field">Reference <span class="hint">cheque no, UTR or UPI ref</span><input name="reference" value="{{ old('reference') }}" maxlength="80"></label>
    @if ($open->count() > 1)<fieldset class="field wide"><legend>Pay these first <span class="hint">otherwise the oldest dues are paid first</span></legend>
        @foreach ($open as $i)<label class="check"><input type="checkbox" name="invoice_ids[]" value="{{ $i->id }}"> {{ $i->number }} · {{ $i->period }} · {{ $money($i->balance()) }}</label>@endforeach</fieldset>@endif
    <label class="field wide">Remarks<input name="remarks" maxlength="300" value="{{ old('remarks') }}"></label>
    <div><button class="btn primary" type="submit">Save receipt</button></div>
</form><p class="small muted">Anything paid beyond the dues is held as an advance and used on the next invoice.</p></section>
@endif
@endcan

<section class="card"><h2>Fee plan · {{ $session->name }}</h2>
@if ($plan)
    <p>{{ $plan->structure->name }}@if ($plan->optional_head_ids) · with {{ \Modules\Fees\Models\FeeHead::whereIn('id', $plan->optional_head_ids)->pluck('name')->join(', ') }}@endif</p>
    @foreach ($plan->concessions as $c)
        <p>{{ $c->concession->label() }} @include('fees::partials.status', ['s' => $c->status]) @if ($c->reason)<span class="small muted">· {{ $c->reason }}</span>@endif
        @if ($c->status === 'pending')@can('academic.fees.approve')
            <form method="post" action="{{ route('app.fees.concessions.decide', $c) }}" class="inline">@csrf<button class="btn sm primary" name="decision" value="approve">Approve</button><button class="btn sm danger" name="decision" value="reject">Reject</button></form>
        @endcan @endif</p>
    @endforeach
    @can('academic.fees.approve')<details><summary>Cancel this plan</summary><form method="post" action="{{ route('app.fees.plans.cancel', $plan) }}" class="form">@csrf
        <label class="field wide">Reason<input name="reason" required minlength="5" placeholder="Student left on 12 Nov"></label><button class="btn danger" type="submit">Cancel plan</button></form><p class="small muted">Unpaid instalments are cancelled and reversed in the books; paid ones are kept.</p></details>@endcan
@else
    @can('academic.fees.configure')
    <form method="post" action="{{ route('app.fees.students.assign', $student) }}" class="form">@csrf
        <label class="field">Fee structure<select name="structure_id" required>@foreach ($structures as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
        <label class="field">Charge from<input type="date" name="starts_from" value="{{ max($session->starts_on->format('Y-m-d'), $student->admitted_on?->format('Y-m-d') ?? '') }}"></label>
        @if ($optionalHeads->isNotEmpty())<fieldset class="field"><legend>Optional heads</legend>@foreach ($optionalHeads as $h)<label class="check"><input type="checkbox" name="optional_head_ids[]" value="{{ $h->id }}"> {{ $h->name }}</label>@endforeach</fieldset>@endif
        @if ($concessions->isNotEmpty())<fieldset class="field"><legend>Concessions</legend>@foreach ($concessions as $c)<label class="check"><input type="checkbox" name="concession_ids[]" value="{{ $c->id }}"> {{ $c->label() }}</label>@endforeach</fieldset>
        <label class="field">Reason for concession<input name="concession_reason" maxlength="300"></label>@endif
        <div><button class="btn primary" type="submit">Assign plan</button></div>
    </form>
    @if ($structures->isEmpty())<p class="empty">No fee structures for {{ $session->name }}. <a href="{{ route('app.fees.structures.create') }}">Create one</a>.</p>@endif
    @else<p class="empty">No fee plan for this session yet.</p>@endcan
@endif
</section>

<section class="card"><h2>Instalments</h2><div class="table-wrap"><table><thead><tr><th>#</th><th>Invoice</th><th>Period</th><th>Due</th><th class="num">Fees</th><th class="num">Concession</th><th class="num">Late fee</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>
@forelse ($invoices as $i)
    <tr><td>{{ $i->instalment }}</td><td>@if ($i->number)<a href="{{ route('app.fees.invoices.show', $i) }}">{{ $i->number }}</a>@else<a href="{{ route('app.fees.invoices.show', $i) }}">issues {{ $i->issue_date->format('j M') }}</a>@endif</td>
    <td>{{ $i->period }}</td><td>{{ $i->due_date->format('j M Y') }}</td><td class="num">{{ $money($i->gross) }}</td><td class="num">{{ $i->concession ? $money($i->concession) : '' }}</td>
    <td class="num">{{ $i->late_fee ? $money($i->late_fee) : '' }}</td><td class="num">{{ $i->paid ? $money($i->paid) : '' }}</td><td class="num">{{ $money($i->status === 'draft' ? $i->gross - $i->concession : $i->balance()) }}</td>
    <td>@include('fees::partials.status', ['s' => $i->status, 'overdue' => $i->isOverdue()])</td></tr>
@empty<tr><td colspan="10" class="empty">No invoices.</td></tr>@endforelse
</tbody></table></div></section>

<section class="card"><h2>Receipts</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Receipt</th><th>Mode</th><th>Reference</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
@forelse ($payments as $p)
    <tr><td>{{ $p->date->format('j M Y') }}</td><td><a href="{{ route('app.fees.payments.show', $p) }}">{{ $p->number }}</a></td><td>{{ \Modules\Fees\Models\FeePayment::MODES[$p->mode] }}</td><td>{{ $p->reference }}</td>
    <td class="num">{{ $money($p->amount) }}@if ($p->refunded)<div class="small muted">refunded {{ $money($p->refunded) }}</div>@endif</td><td>@include('fees::partials.status', ['s' => $p->status])</td></tr>
@empty<tr><td colspan="6" class="empty">No payments yet.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
