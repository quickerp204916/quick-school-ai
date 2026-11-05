@extends('academic::layout')
@section('title', 'Edit '.$s->fullName())
@section('content')
<div class="head"><h1>Edit {{ $s->fullName() }}</h1><a class="btn" href="{{ route('app.students.show', $s) }}">Cancel</a></div>
<form class="card" method="post" action="{{ route('app.students.update', $s) }}">@csrf @method('put')
    @include('academic::students.fields')
    <div class="form" style="margin-top:14px"><label class="field">Conduct <span class="hint">printed on certificates</span><input type="text" name="conduct" value="{{ old('conduct', $s->conduct) }}" maxlength="40"></label></div>
    <p><button class="btn primary" type="submit">Save</button></p>
</form>
@endsection
