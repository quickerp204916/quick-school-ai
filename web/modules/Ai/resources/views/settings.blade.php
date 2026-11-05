@extends('academic::layout')
@section('title', 'AI settings')
@section('content')
<div class="head"><h1>AI settings</h1></div>
@include('ai::partials.tabs')
<form method="post" action="{{ route('app.ai.settings.save') }}" class="card" style="display:grid;gap:14px">@csrf @method('put')
    <label class="check"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($s->enabled)> AI assistants are on for this institution</label>
    <label class="field" style="max-width:280px">Default reply language<select name="language">@foreach ($languages as $k => $l)<option value="{{ $k }}" @selected($s->language === $k)>{{ $l }}</option>@endforeach</select></label>
    <div class="table-wrap"><table><thead><tr><th>Assistant</th><th>On</th><th>Model</th></tr></thead><tbody>
    @foreach ($agents as $k => $a)@php([$p, $m] = $s->modelFor($k))
        <tr><td><b>{{ $a['label'] }}</b><br><span class="small muted">{{ $a['description'] }}</span></td>
        <td><input type="checkbox" name="agents[]" value="{{ $k }}" aria-label="{{ $a['label'] }} on" @checked($s->agents_enabled === null || in_array($k, $s->agents_enabled, true))></td>
        <td><select name="model[{{ $k }}]" aria-label="{{ $a['label'] }} model">@foreach ($providers as $pk => $pv)<optgroup label="{{ $pv['label'] }}">@foreach ($pv['models'] as $mod)<option value="{{ $pk }}:{{ $mod }}" @selected($p === $pk && $m === $mod)>{{ $mod }}</option>@endforeach</optgroup>@endforeach</select></td></tr>
    @endforeach</tbody></table></div>
    <div><button class="btn primary" type="submit">Save</button></div></form>
@endsection
