@extends('academic::layout')
@section('title', $doc->original_name)
@section('content')
<div class="head"><h1>{{ $doc->original_name }}</h1><div class="toolbar"><span class="chip {{ $doc->status }}">{{ $doc->status }}</span></div></div>
@include('ai::partials.tabs')
@if ($doc->error)<p class="alert">{{ $doc->error }}</p>@endif
@php($ex = $doc->extracted ?? [])
@if (! empty($ex['missing']) || ! empty($ex['warnings']))<p class="alert">@if (! empty($ex['missing']))Not found: {{ implode(', ', $ex['missing']) }}. @endif @foreach ($ex['warnings'] ?? [] as $w){{ $w }} @endforeach</p>@endif
<form method="post" action="{{ route('app.ai.documents.review', $doc) }}" class="card" style="display:grid;gap:12px">@csrf
    <h2>{{ $types[$doc->doc_type] ?? $doc->doc_type }}: fields read</h2>
    <div class="form">@foreach (($ex['fields'] ?? []) as $k => $v)@if (! is_array($v))<label class="field">{{ ucfirst(str_replace('_', ' ', $k)) }}<input name="fields[{{ $k }}]" value="{{ $v }}"></label>@endif @endforeach</div>
    @foreach (($ex['fields'] ?? []) as $k => $v)@if (is_array($v) && $v)<h3>{{ ucfirst(str_replace('_', ' ', $k)) }}</h3><div class="table-wrap"><table><thead><tr>@foreach (array_keys((array) $v[0]) as $c)<th>{{ str_replace('_', ' ', $c) }}</th>@endforeach</tr></thead><tbody>
        @foreach ($v as $row)<tr>@foreach ((array) $row as $cell)<td>{{ is_scalar($cell) ? $cell : '' }}</td>@endforeach</tr>@endforeach</tbody></table></div>@endif @endforeach
    @if ($doc->status !== 'failed')<div><button class="btn primary" type="submit">Save corrections and mark checked</button></div>@endif
</form>
@endsection
