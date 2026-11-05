@extends('academic::layout')
@section('title', $l->name)
@section('content')
<div class="head"><h1>{{ $l->name }}</h1><a class="btn" href="{{ route('app.accounts.chart') }}">Back</a></div>
@if ($l->system_key)<div class="alert ok" style="background:var(--royal-50);color:var(--royal-700)">Built-in ledger for automatic postings ({{ $l->system_key }}). You can rename it, but not change its type or make it inactive.</div>@endif
<form class="card" method="post" action="{{ route('app.accounts.ledgers.update', $l) }}">@csrf @method('put')
    @include('accounting::chart.ledger-fields')
    <p><button class="btn primary" type="submit">Save</button></p>
</form>
@endsection
