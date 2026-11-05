@extends('academic::layout')
@section('title', 'Knowledge base')
@section('content')
<div class="head"><h1>Knowledge base</h1></div>
@include('ai::partials.tabs')
<p class="small muted">Your policies, fee rules, admission criteria and circulars. Assistants search these to answer questions, and only show a document to the roles you choose.</p>
<details class="card"><summary>Add a document</summary><form method="post" action="{{ route('app.ai.knowledge.save') }}" class="form" style="margin-top:12px">@csrf
    <label class="field wide">Title<input name="title" required maxlength="150"></label>
    <label class="field wide">Text<textarea name="content" rows="10" required></textarea></label>
    <fieldset class="wide"><legend class="small muted">Who may see it (none ticked = everyone)</legend>@foreach ($roles as $k => $l)<label class="check"><input type="checkbox" name="roles[]" value="{{ $k }}"> {{ $l }}</label>@endforeach</fieldset>
    <button class="btn primary" type="submit">Save and index</button></form></details>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Title</th><th>Visible to</th><th>Parts</th><th>Indexed</th><th></th></tr></thead><tbody>
@forelse ($sources as $s)<tr><td>{{ $s->title }}@if ($s->error)<br><span class="small" style="color:var(--bad)">{{ $s->error }}</span>@endif</td><td class="small">{{ $s->roles ? collect($s->roles)->map(fn ($r) => $roles[$r] ?? $r)->join(', ') : 'Everyone' }}</td>
<td class="num">{{ $s->chunks }}</td><td>{{ $s->indexed_at?->format('j M Y') ?? 'Not yet' }}</td>
<td><form method="post" action="{{ route('app.ai.knowledge.reindex', $s) }}" class="inline">@csrf<button class="btn sm" type="submit">Re-index</button></form>
<form method="post" action="{{ route('app.ai.knowledge.delete', $s) }}" class="inline">@csrf @method('delete')<button class="btn sm" type="submit">Remove</button></form></td></tr>
@empty<tr><td colspan="5" class="empty">Nothing yet.</td></tr>@endforelse</tbody></table></div></section>
@endsection
