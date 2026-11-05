@extends('academic::layout')
@section('title', 'Fee approvals')
@section('content')
<div class="head"><h1>Fee approvals</h1></div>
@include('fees::partials.tabs')
<p class="muted">Concessions and refunds need an owner or administrator, and never the person who asked for them.</p>
<section class="card"><h2>Concessions waiting</h2><div class="table-wrap"><table><tbody>
@forelse ($concessions as $c)
    <tr><td><a href="{{ route('app.fees.students.show', $c->plan->student) }}">{{ $c->plan->student->fullName() }}</a></td><td>{{ $c->concession->label() }}</td><td>{{ $c->reason }}</td>
    <td>@can('academic.fees.approve')<form method="post" action="{{ route('app.fees.concessions.decide', $c) }}" class="inline">@csrf<button class="btn sm primary" name="decision" value="approve">Approve</button><button class="btn sm danger" name="decision" value="reject">Reject</button></form>@endcan</td></tr>
@empty<tr><td class="empty">None waiting.</td></tr>@endforelse</tbody></table></div></section>
<section class="card"><h2>Refunds</h2><div class="table-wrap"><table><thead><tr><th>Receipt</th><th>Student</th><th class="num">Amount</th><th>Reason</th><th>Asked by</th><th>Status</th><th></th></tr></thead><tbody>
@forelse ($refunds as $r)
    <tr><td><a href="{{ route('app.fees.payments.show', $r->payment) }}">{{ $r->payment->number }}</a></td><td>{{ $r->payment->student->fullName() }}</td><td class="num">{{ $money($r->amount) }}</td><td>{{ $r->reason }}@if ($r->failure)<div class="small muted">{{ $r->failure }}</div>@endif</td>
    <td>{{ $r->requester?->name }}</td><td>@include('fees::partials.status', ['s' => $r->status])</td>
    <td>@can('academic.fees.approve')
        @if ($r->status === 'requested')<form method="post" action="{{ route('app.fees.refunds.approve', $r) }}" class="inline">@csrf<button class="btn sm primary">Approve</button></form>
            <details><summary>Reject</summary><form method="post" action="{{ route('app.fees.refunds.reject', $r) }}">@csrf<input name="reason" required minlength="5" placeholder="Reason"><button class="btn sm danger">Reject</button></form></details>
        @elseif ($r->gateway_refund_id && $r->status === 'approved')<span class="small muted">Waiting for {{ ucfirst($r->mode) }} to confirm</span>
        @else<form method="post" action="{{ route('app.fees.refunds.process', $r) }}" class="inline">@csrf
            @if ($r->mode === 'bank')<input name="reference" placeholder="UTR of the refund" required>@endif
            <button class="btn sm primary">{{ in_array($r->mode, ['razorpay', 'cashfree'], true) ? 'Refund through '.ucfirst($r->mode) : 'Mark as paid' }}</button></form>@endif
    @endcan</td></tr>
@empty<tr><td colspan="7" class="empty">No refunds waiting.</td></tr>@endforelse</tbody></table></div></section>
@if ($done->isNotEmpty())<section class="card"><h2>Recently finished</h2><div class="table-wrap"><table><tbody>@foreach ($done as $r)<tr><td>{{ $r->payment->number }}</td><td>{{ $r->payment->student->fullName() }}</td><td class="num">{{ $money($r->amount) }}</td><td>@include('fees::partials.status', ['s' => $r->status])</td></tr>@endforeach</tbody></table></div></section>@endif
@endsection
