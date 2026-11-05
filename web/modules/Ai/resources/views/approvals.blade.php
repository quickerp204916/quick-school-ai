@extends('academic::layout')
@section('title', 'AI approvals')
@section('content')
<div class="head"><h1>AI approvals</h1></div>
@include('ai::partials.tabs')
<p class="small muted">Requests an assistant prepared for someone. Approving runs the action through the normal accounting, fee or payroll rules, with the person who asked as the maker and you as the checker. You can't approve your own request.</p>
<section class="card"><h2>Waiting</h2><div class="table-wrap"><table><thead><tr><th>Asked</th><th>By</th><th>Request</th><th></th></tr></thead><tbody>
@forelse ($pending as $r)
    <tr><td>{{ $r->created_at->format('j M, g:i a') }}</td><td>{{ $r->requester?->name }}</td><td><b>{{ \Modules\Ai\Models\AiActionRequest::TYPES[$r->type] ?? $r->type }}</b><br>{{ $r->summary }}
        @if ($r->type === 'accounting.voucher')<div class="small muted">@foreach ($r->payload['lines'] as $l){{ $l['ledger'] }}: {{ $l['debit'] ? 'Dr '.$money($l['debit']) : 'Cr '.$money($l['credit']) }}@if (! $loop->last) · @endif @endforeach</div>@endif</td>
    <td>@if ($proposals->canDecide($r))
        <form method="post" action="{{ route('app.ai.approvals.approve', $r) }}" class="inline">@csrf<button class="btn sm primary" type="submit">Approve</button></form>
        <details><summary>Reject</summary><form method="post" action="{{ route('app.ai.approvals.reject', $r) }}" class="form">@csrf<label class="field">Reason<input name="reason" required></label><button class="btn sm" type="submit">Reject</button></form></details>
        @else<span class="small muted">{{ $r->requested_by === auth()->id() ? 'Your request' : 'Needs '.str_replace('.', ' ', $r->permission) }}</span>@endif</td></tr>
@empty<tr><td colspan="4" class="empty">Nothing is waiting.</td></tr>@endforelse</tbody></table></div></section>
<section class="card"><h2>Decided</h2><div class="table-wrap"><table><tbody>
@forelse ($decided as $r)<tr><td>{{ $r->decided_at?->format('j M') }}</td><td><span class="chip {{ $r->status }}">{{ $r->status }}</span></td><td>{{ $r->summary }}</td><td class="small muted">{{ $r->result }}</td></tr>
@empty<tr><td class="empty">None yet.</td></tr>@endforelse</tbody></table></div></section>
@endsection
