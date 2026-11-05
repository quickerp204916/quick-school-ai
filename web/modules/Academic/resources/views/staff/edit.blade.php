@extends('academic::layout')
@section('title', $s->name)
@section('content')
<div class="head"><h1>{{ $s->name }}</h1><a class="btn" href="{{ route('app.staff.index') }}">Back to staff</a></div>
<form class="card" method="post" action="{{ route('app.staff.update', $s) }}">@csrf @method('put')
    @include('academic::staff.fields')
    <p><button class="btn primary" type="submit">Save</button></p>
</form>
@if ($classes->isNotEmpty())
<section class="card"><h2>Class teacher of</h2>
    @foreach ($classes as $c)<a class="chip" href="{{ route('app.sections.show', $c) }}">{{ $c->label() }} · {{ $c->session->name }}</a> @endforeach
</section>
@endif
@endsection
