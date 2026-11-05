@extends('academic::layout')
@section('title', config('ai.agents.'.$agent.'.label', 'AI assistant'))
@section('content')
<div class="head"><h1>{{ config('ai.agents.'.$agent.'.label', 'AI assistant') }}</h1><div class="toolbar"><a class="btn" href="{{ route('app.ai.chat', ['agent' => $agent]) }}">New conversation</a></div></div>
@include('ai::partials.tabs')
<div class="chat">
    <aside class="card history"><h2>Conversations</h2>
        @forelse ($history as $h)<a class="{{ $conversation?->id === $h->id ? 'on' : '' }}" href="{{ route('app.ai.chat', ['c' => $h->id]) }}" title="{{ $h->title }}">{{ $h->title }}</a>@empty<p class="small muted">None yet.</p>@endforelse
    </aside>
    <section style="display:grid;gap:14px">
        @unless ($conversation)
            <p class="small muted">{{ config('ai.agents.'.$agent.'.description') }} Answers use your permissions; anything that would post money waits for approval.</p>
        @endunless
        <div class="thread" aria-live="polite">
        @foreach ($messages as $m)
            <div class="msg {{ $m->role }}">{{ $m->content }}
                @foreach ($m->tool_calls['approvals'] ?? [] as $id)@if ($r = $approvals[$id] ?? null)
                    <div class="proposal"><span class="chip {{ $r->status }}">{{ $r->status }}</span> <b>{{ \Modules\Ai\Models\AiActionRequest::TYPES[$r->type] ?? $r->type }}</b>: {{ $r->summary }}
                    @if ($r->status === 'pending') <span class="muted">· Waiting for an approver. Nothing has been posted.</span>@elseif ($r->result) <span class="muted">· {{ $r->result }}</span>@endif</div>
                @endif @endforeach
                @if ($m->role === 'assistant')<span class="meta">{{ collect($m->tool_calls['calls'] ?? [])->pluck('tool')->unique()->join(', ') ?: 'No ERP data used' }} · {{ $m->model }} · {{ $m->credits }} credit(s)</span>@endif
            </div>
        @endforeach
        </div>
        <form method="post" action="{{ route('app.ai.send') }}" class="card" style="display:grid;gap:10px">@csrf
            <input type="hidden" name="agent" value="{{ $agent }}">@if ($conversation)<input type="hidden" name="conversation_id" value="{{ $conversation->id }}">@endif
            @unless ($conversation)<div class="row" style="display:flex;gap:10px;flex-wrap:wrap">
                <label class="field">Assistant<select name="agent">@foreach ($agents as $k => $a)<option value="{{ $k }}" @selected($k === $agent)>{{ $a['label'] }}</option>@endforeach</select></label>
                <label class="field">Reply language<select name="language"><option value="">Same as my question</option>@foreach ($languages as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label></div>@endunless
            <div class="composer"><label class="field" style="flex:1">Your question<textarea name="message" required maxlength="4000" placeholder="For example: Which students in Class 5 have attendance below 75% this month?">{{ old('message') }}</textarea></label>
            <button class="btn primary" type="submit">Send</button></div>
            @error('message')<p class="small" style="color:var(--bad)">{{ $message }}</p>@enderror
        </form>
    </section>
</div>
@endsection
