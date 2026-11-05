@extends('academic::layout')
@section('title', 'AI usage and credits')
@section('content')
<div class="head"><h1>AI usage and credits</h1></div>
@include('ai::partials.tabs')
<div class="grid"><div class="card kpi"><div class="label">Credits left</div><div class="value">{{ number_format($balance) }}</div></div>
<div class="card kpi"><div class="label">Used this month</div><div class="value">{{ number_format($used) }}</div></div></div>
<p class="small muted">Credits are charged per 1,000 tokens by model, and a fixed amount per document read. Top-ups come with your plan or from Quick School AI support.</p>
<div class="cols">
<section class="card"><h2>This month by assistant</h2><div class="table-wrap"><table><thead><tr><th>Assistant</th><th class="num">Replies</th><th class="num">Tokens</th><th class="num">Credits</th></tr></thead><tbody>
@forelse ($byAgent as $r)<tr><td>{{ config('ai.agents.'.$r->agent.'.label') }}</td><td class="num">{{ $r->turns }}</td><td class="num">{{ number_format($r->tokens) }}</td><td class="num">{{ number_format($r->credits) }}</td></tr>
@empty<tr><td class="empty">No use yet.</td></tr>@endforelse</tbody></table></div></section>
<section class="card"><h2>This month by person</h2><div class="table-wrap"><table><tbody>
@forelse ($byUser as $r)<tr><td>{{ $r->name }}</td><td class="num">{{ $r->turns }} replies</td><td class="num">{{ number_format($r->credits) }} credits</td></tr>
@empty<tr><td class="empty">No use yet.</td></tr>@endforelse</tbody></table></div></section></div>
<section class="card"><h2>Credit history</h2><div class="table-wrap"><table><tbody>
@foreach ($entries as $e)<tr><td>{{ $e->created_at->format('j M Y, g:i a') }}</td><td>{{ str_replace('_', ' ', $e->kind) }}</td><td>{{ $e->note }}</td><td class="num">{{ $e->amount > 0 ? '+' : '' }}{{ number_format($e->amount) }}</td></tr>@endforeach</tbody></table></div></section>
@endsection
