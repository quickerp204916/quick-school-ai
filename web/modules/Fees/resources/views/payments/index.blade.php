@extends('academic::layout')
@section('title', 'Fee receipts')
@section('content')
<div class="head"><h1>Fee receipts</h1></div>
@include('fees::partials.tabs')
<form method="get" class="filters"><label class="field">From<input type="date" name="from" value="{{ $from }}"></label><label class="field">To<input type="date" name="to" value="{{ $to }}"></label>
    <label class="field">Mode<select name="mode"><option value="">All</option>@foreach (\Modules\Fees\Models\FeePayment::MODES as $k => $l)<option value="{{ $k }}" @selected(request('mode') === $k)>{{ $l }}</option>@endforeach</select></label><button class="btn" type="submit">Show</button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Date</th><th>Receipt</th><th>Student</th><th>Mode</th><th>Reference</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
@forelse ($list as $p)
    <tr><td>{{ $p->date->format('j M Y') }}</td><td><a href="{{ route('app.fees.payments.show', $p) }}">{{ $p->number }}</a></td><td>{{ $p->student->fullName() }}</td><td>{{ \Modules\Fees\Models\FeePayment::MODES[$p->mode] }}</td><td>{{ $p->reference }}</td><td class="num">{{ $money($p->amount) }}</td><td>@include('fees::partials.status', ['s' => $p->status])</td></tr>
@empty<tr><td colspan="7" class="empty">No receipts in this period.</td></tr>@endforelse
</tbody></table></div>@include('academic::partials.pager', ['p' => $list])</section>
@can('academic.fees.approve')
<details class="card"><summary>Record a gateway settlement</summary>
<p class="small muted">When Razorpay or Cashfree pays collections into your bank. Razorpay settlements also arrive automatically by webhook. Each settlement id is posted once.</p>
<form method="post" action="{{ route('app.fees.settlements.store') }}" class="form">@csrf
    <label class="field">Gateway<select name="gateway"><option value="razorpay">Razorpay</option><option value="cashfree">Cashfree</option></select></label>
    <label class="field">Settlement id or UTR<input name="settlement_id" required maxlength="40"></label>
    <label class="field">Date<input type="date" name="date" value="{{ today()->format('Y-m-d') }}" required></label>
    <label class="field">Gross collected (₹)<input name="gross" inputmode="decimal" required></label>
    <label class="field">Gateway fee (₹)<input name="fee" inputmode="decimal" value="0"></label>
    <label class="field">GST on the fee (₹)<input name="gst" inputmode="decimal" value="0"></label>
    <button class="btn primary" type="submit">Post settlement</button></form></details>
@endcan
@endsection
