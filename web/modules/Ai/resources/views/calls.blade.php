@extends('academic::layout')
@section('title', 'Call log')
@section('content')
<div class="head"><h1>Call log</h1></div>
@include('ai::partials.tabs')
<p class="small muted">Calls logged by the Voice Calling Assistant or by staff through an assistant.</p>
<section class="card"><div class="table-wrap"><table><thead><tr><th>When</th><th>Purpose</th><th>Student</th><th>Phone</th><th>Outcome</th><th>Notes</th><th>Next</th></tr></thead><tbody>
@forelse ($calls as $c)<tr><td>{{ $c->created_at->format('j M, g:i a') }}</td><td>{{ str_replace('_', ' ', $c->purpose) }}</td><td>{{ $c->student?->fullName() }}</td><td>{{ $c->phone }}</td><td>{{ str_replace('_', ' ', $c->outcome) }}</td><td>{{ $c->notes }}</td><td>{{ $c->next_action_on?->format('j M') }}</td></tr>
@empty<tr><td colspan="7" class="empty">No calls logged yet.</td></tr>@endforelse</tbody></table></div></section>
@endsection
