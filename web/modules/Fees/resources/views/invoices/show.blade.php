@extends('academic::layout')
@section('title', 'Fee invoice '.($inv->number ?? ''))
@section('content')
<div class="head"><div><h1>Fee invoice {{ $inv->number ?? '(scheduled)' }}</h1><p class="muted" style="margin:4px 0 0">{{ $inv->student->fullName() }} · {{ $inv->period }}</p></div>
    <a class="btn" href="{{ route('app.fees.students.show', $inv->student) }}">Student's fees</a></div>
<div class="cols">
<section class="card"><dl class="kv"><dt>Status</dt><dd>@include('fees::partials.status', ['s' => $inv->status, 'overdue' => $inv->isOverdue()])</dd>
    <dt>Issued</dt><dd>{{ $inv->issue_date->format('j M Y') }}{{ $inv->status === 'draft' ? ' (scheduled)' : '' }}</dd><dt>Due</dt><dd>{{ $inv->due_date->format('j M Y') }}</dd>
    <dt>Structure</dt><dd>{{ $inv->plan->structure->name }}</dd>
    @if ($inv->voucher_id)<dt>Posted as</dt><dd><a href="{{ route('app.accounts.vouchers.show', $inv->voucher_id) }}">accounting voucher</a></dd>@endif</dl></section>
<section class="card"><div class="table-wrap"><table><tbody>
    <tr><td>Fees</td><td class="num">{{ $money($inv->gross) }}</td></tr>
    @if ($inv->concession)<tr><td>Concession</td><td class="num">−{{ $money($inv->concession) }}</td></tr>@endif
    @if ($inv->late_fee)<tr><td>Late fee</td><td class="num">{{ $money($inv->late_fee) }}</td></tr>@endif
    @if ($inv->waived)<tr><td>Waived</td><td class="num">−{{ $money($inv->waived) }}</td></tr>@endif
    <tr><td><b>Total</b></td><td class="num"><b>{{ $money($inv->total()) }}</b></td></tr>
    <tr><td>Paid</td><td class="num">{{ $money($inv->paid) }}</td></tr>
    <tr><td><b>Balance</b></td><td class="num"><b>{{ $money($inv->balance()) }}</b></td></tr></tbody></table></div></section>
</div>
<section class="card"><h2>Lines</h2><div class="table-wrap"><table><thead><tr><th>Description</th><th class="num">Amount</th><th class="num">Concession</th><th class="num">Net</th></tr></thead><tbody>
@foreach ($inv->lines as $l)<tr><td>{{ $l->description }}</td><td class="num">{{ $money($l->amount) }}</td><td class="num">{{ $l->concession ? $money($l->concession) : '' }}</td><td class="num">{{ $money($l->amount - $l->concession) }}</td></tr>@endforeach
</tbody></table></div></section>
@if ($inv->allocations->isNotEmpty())
<section class="card"><h2>Payments against this invoice</h2><div class="table-wrap"><table><tbody>
@foreach ($inv->allocations as $a)<tr><td><a href="{{ route('app.fees.payments.show', $a->payment) }}">{{ $a->payment->number }}</a></td><td>{{ $a->payment->date->format('j M Y') }}</td><td>{{ $a->via === 'advance' ? 'from advance' : \Modules\Fees\Models\FeePayment::MODES[$a->payment->mode] }}</td><td>@include('fees::partials.status', ['s' => $a->payment->status])</td><td class="num">{{ $money($a->amount) }}</td></tr>@endforeach
</tbody></table></div></section>
@endif
@can('academic.fees.approve')
@if (in_array($inv->status, ['open', 'partial'], true))
<section class="card"><h2>Waive part of the balance</h2><form method="post" action="{{ route('app.fees.invoices.waive', $inv) }}" class="form">@csrf<input type="hidden" name="token" value="{{ $token }}">
    <label class="field">Amount (₹)<input name="amount" value="{{ \Modules\Accounting\Support\Amount::input($inv->late_fee ?: $inv->balance()) }}" required></label>
    <label class="field wide">Reason<input name="reason" required minlength="5" placeholder="Late fee waived: parent was hospitalised"></label><div><button class="btn" type="submit">Waive</button></div></form></section>
@endif
@if ($inv->paid === 0 && $inv->status !== 'cancelled')
<details class="card danger-zone"><summary>Cancel this invoice</summary><form method="post" action="{{ route('app.fees.invoices.cancel', $inv) }}" class="form">@csrf
    <label class="field wide">Reason<input name="reason" required minlength="5"></label><button class="btn danger" type="submit">Cancel invoice</button></form><p class="small muted">An issued invoice is reversed in the books; nothing is deleted.</p></details>
@endif
@endcan
@endsection
