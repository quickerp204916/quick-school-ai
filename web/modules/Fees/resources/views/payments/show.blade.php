@extends('academic::layout')
@section('title', 'Receipt '.$p->number)
@section('content')
<div class="head no-print"><h1>Receipt {{ $p->number }}</h1><div class="toolbar"><button class="btn" type="button" onclick="window.print()">Print</button><a class="btn" href="{{ route('app.fees.students.show', $p->student) }}">Student's fees</a></div></div>
@include('fees::payments.receipt-body')
<div class="cols no-print">
@if ($p->status === 'success' && $p->refundable() > 0)@can('academic.fees.collect')
<section class="card"><h2>Request a refund</h2><form method="post" action="{{ route('app.fees.payments.refund', $p) }}" class="form">@csrf
    <label class="field">Amount (₹) <span class="hint">up to {{ $money($p->refundable()) }}</span><input name="amount" required></label>
    @unless (in_array($p->mode, ['razorpay', 'cashfree'], true))<label class="field">Pay back by<select name="mode"><option value="bank">Bank transfer</option><option value="cash">Cash</option></select></label>@endunless
    <label class="field wide">Reason<input name="reason" required minlength="5" placeholder="Paid twice by mistake"></label><div><button class="btn" type="submit">Request refund</button></div></form>
    <p class="small muted">{{ in_array($p->mode, ['razorpay', 'cashfree'], true) ? 'Refunded through '.ucfirst($p->mode).' to the original payment method.' : 'An approver must approve the refund before it is paid.' }}</p></section>
@endcan @endif
@if ($p->mode === 'cheque' && $p->status === 'success')@can('academic.fees.approve')
<section class="card"><h2>Cheque returned by the bank</h2><form method="post" action="{{ route('app.fees.payments.bounce', $p) }}" class="form">@csrf
    <label class="field wide">Bank's reason<input name="reason" required minlength="5" placeholder="Insufficient funds"></label><button class="btn danger" type="submit">Mark as bounced</button></form></section>
@endcan @endif
@if ($p->refunds->isNotEmpty())<section class="card"><h2>Refunds</h2>@foreach ($p->refunds as $r)<p>{{ $money($r->amount) }} · {{ $r->reason }} · @include('fees::partials.status', ['s' => $r->status])</p>@endforeach</section>@endif
</div>
<p class="small muted no-print">Received by {{ $p->receiver?->name ?? 'online payment' }}. @if ($p->voucher_id)<a href="{{ route('app.accounts.vouchers.show', $p->voucher_id) }}">Accounting voucher</a>@endif</p>
@endsection
