@extends('academic::layout')
@section('title', 'Prompts')
@section('content')
<div class="head"><h1>Prompts</h1></div>
@include('ai::partials.tabs')
<nav class="toolbar">@foreach ($agents as $k => $a)<a class="btn sm {{ $k === $agent ? 'primary' : '' }}" href="{{ route('app.ai.prompts', ['agent' => $k]) }}">{{ $a['label'] }}</a>@endforeach</nav>
<p class="small muted">Each save is a new version; earlier versions stay for the record. The safety rules (no direct postings, approvals, honest answers) are always added by the AI service and can't be removed here.</p>
<form method="post" action="{{ route('app.ai.prompts.save') }}" class="card" style="display:grid;gap:12px">@csrf<input type="hidden" name="agent" value="{{ $agent }}">
    <label class="field">Prompt for {{ $agents[$agent]['label'] }}<textarea name="prompt" rows="8" required>{{ old('prompt', $versions->firstWhere('is_active', true)?->prompt) }}</textarea></label>
    <label class="field">Note (what changed)<input name="note" maxlength="200"></label>
    <div class="toolbar"><button class="btn primary" type="submit">Save as a new version</button></div></form>
<form method="post" action="{{ route('app.ai.prompts.default') }}" class="inline">@csrf<input type="hidden" name="agent" value="{{ $agent }}"><button class="btn sm" type="submit">Use the built-in prompt</button></form>
<section class="card"><h2>Versions</h2><div class="table-wrap"><table><tbody>
@forelse ($versions as $v)<tr><td>v{{ $v->version }}</td><td>{{ $v->is_active ? 'Active' : '' }}</td><td class="small">{{ \Illuminate\Support\Str::limit($v->prompt, 140) }}<br><span class="muted">{{ $v->note }}</span></td><td>{{ $v->created_at->format('j M Y') }}</td>
<td>@unless ($v->is_active)<form method="post" action="{{ route('app.ai.prompts.activate', $v) }}" class="inline">@csrf<button class="btn sm" type="submit">Make active</button></form>@endunless</td></tr>
@empty<tr><td class="empty">Using the built-in prompt.</td></tr>@endforelse</tbody></table></div></section>
@endsection
