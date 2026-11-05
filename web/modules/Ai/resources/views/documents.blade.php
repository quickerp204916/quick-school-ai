@extends('academic::layout')
@section('title', 'Read documents')
@section('content')
<div class="head"><h1>Read documents</h1></div>
@include('ai::partials.tabs')
<section class="card"><h2>Upload a document</h2><p class="small muted">The assistant reads the fields for you to check. Nothing is saved to a student or posted to the books from here. Aadhaar and bank account numbers are never extracted.</p>
<form method="post" action="{{ route('app.ai.documents.upload') }}" enctype="multipart/form-data" class="form">@csrf
    <label class="field">Kind of document<select name="doc_type">@foreach ($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
    <label class="field">File (PDF, JPG, PNG, up to 10 MB)<input type="file" name="file" accept=".pdf,image/jpeg,image/png,image/webp" required></label>
    <button class="btn primary" type="submit">Read it</button></form></section>
<section class="card"><h2>Recent</h2><div class="table-wrap"><table><tbody>
@forelse ($documents as $d)<tr><td><a href="{{ route('app.ai.documents.show', $d) }}">{{ $d->original_name }}</a></td><td>{{ $types[$d->doc_type] ?? $d->doc_type }}</td><td><span class="chip {{ $d->status }}">{{ $d->status }}</span></td><td class="num">{{ $d->created_at->format('j M') }}</td></tr>
@empty<tr><td class="empty">No documents yet.</td></tr>@endforelse</tbody></table></div></section>
@endsection
