@extends('academic::layout')
@section('title', $c->number)
@section('content')
<div class="toolbar no-print"><button class="btn primary" type="button" onclick="print()">Print or save as PDF</button>
    <a class="btn" href="{{ route('app.students.show', $c->student) }}">Student record</a>
    @if ($c->status === 'issued')<details><summary class="btn danger">Cancel certificate</summary>
        <form method="post" action="{{ route('app.certificates.cancel', $c) }}" class="filters" style="margin-top:8px">@csrf
            <label class="field grow">Reason<input type="text" name="reason" required minlength="5" maxlength="200"></label><button class="btn danger" type="submit">Cancel</button></form></details>@endif</div>
@if ($c->status === 'cancelled')<div class="alert err">Cancelled {{ $c->cancelled_at?->format('j M Y') }}: {{ $c->cancel_reason }}</div>@endif
<article class="doc">
    <header><h1>{{ $tenant->name }}</h1>
        <div class="small">{{ $profile->address }}</div>
        <div class="small">{{ collect([$profile->board ? $profile->board.' affiliated' : null, $profile->affiliation_no ? 'Affiliation no '.$profile->affiliation_no : null, $profile->udise_code ? 'UDISE '.$profile->udise_code : null, $profile->phone, $profile->email])->filter()->implode(' · ') }}</div>
        <h2 style="margin-top:14px;text-transform:uppercase;letter-spacing:.06em">{{ $c->data['title'] ?? config('academic.certificate_types.'.$c->type.'.label') }}</h2></header>
    <p class="small" style="display:flex;justify-content:space-between"><span>No. <b>{{ $c->number }}</b></span><span>Date: {{ $c->issued_on->format('j F Y') }}</span></p>
    <div class="body">{{ $c->body }}</div>
    <div class="sign"><span>Prepared by</span><span>Seal</span><span>{{ $profile->principal_name ?: 'Principal' }}<br>Principal</span></div>
</article>
@endsection
