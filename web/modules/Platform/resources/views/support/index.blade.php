@extends('platform::layout')
@section('title', 'Support access')
@section('content')
<div class="head"><div><h1>Support access</h1><p class="muted" style="margin:4px 0 0">Start a session from an institution's page. Sessions end on their own after the time chosen.</p></div></div>
<section class="card"><h2>Active now</h2><div class="table-wrap"><table>
    <thead><tr><th>Institution</th><th>Staff</th><th>Reason</th><th>Ends</th><th></th></tr></thead><tbody>
    @forelse ($active as $s)
        <tr><td><a href="{{ route('platform.tenants.show', $s->tenant) }}">{{ $s->tenant->name }}</a></td><td>{{ $s->admin->name }}</td><td>{{ $s->reason }}</td><td>{{ $s->expires_at->format('H:i') }}</td>
            <td><form class="inline" method="post" action="{{ route('platform.support.end', $s) }}">@csrf<button class="btn sm" type="submit">End</button></form></td></tr>
    @empty <tr><td colspan="5" class="muted">No active sessions.</td></tr> @endforelse
    </tbody></table></div></section>
<section class="card"><h2>Recent sessions</h2><div class="table-wrap"><table>
    <thead><tr><th>Started</th><th>Institution</th><th>Staff</th><th>Reason</th><th>Ended</th></tr></thead><tbody>
    @forelse ($recent as $s)
        <tr><td class="small">{{ $s->started_at->format('j M, H:i') }}</td><td>{{ $s->tenant->name }}</td><td>{{ $s->admin->name }}</td><td>{{ $s->reason }}</td>
            <td class="small">{{ $s->ended_at ? $s->ended_at->format('H:i') : ($s->expires_at->isPast() ? 'expired '.$s->expires_at->format('H:i') : 'active') }}</td></tr>
    @empty <tr><td colspan="5" class="muted">No sessions yet.</td></tr> @endforelse
    </tbody></table></div></section>
@endsection
