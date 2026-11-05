@extends('academic::layout')
@section('title', 'AI assistants')
@section('content')
<div class="head"><h1>AI assistants</h1><div class="toolbar"><span class="small muted">{{ number_format($credits) }} AI credits left</span></div></div>
@include('ai::partials.tabs')
<p class="small muted">Assistants read your institution's data through the same permissions you have. They never post vouchers, refunds, waivers or payroll themselves: they send a request that an authorised person approves.</p>
@if ($pending)<p class="alert"><a href="{{ route('app.ai.approvals') }}">{{ $pending }} AI request(s) waiting for approval</a></p>@endif
<div class="grid">
@forelse ($agents as $key => $a)
    <a class="card" href="{{ route('app.ai.chat', ['agent' => $key]) }}" style="text-decoration:none;color:inherit"><h2>{{ $a['label'] }}</h2><p class="small muted">{{ $a['description'] }}</p></a>
@empty<p class="empty">AI assistants are switched off for your institution.</p>@endforelse
</div>
<section class="card"><h2>Your recent conversations</h2><div class="table-wrap"><table><tbody>
@forelse ($conversations as $c)<tr><td><a href="{{ route('app.ai.chat', ['c' => $c->id]) }}">{{ $c->title }}</a></td><td>{{ config('ai.agents.'.$c->agent.'.label') }}</td><td class="num">{{ $c->updated_at->diffForHumans() }}</td></tr>
@empty<tr><td class="empty">No conversations yet.</td></tr>@endforelse</tbody></table></div></section>
@endsection
