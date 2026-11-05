@extends('academic::layout')
@section('title', 'Add employee')
@section('content')
<div class="head"><h1>Add employee</h1><a class="btn" href="{{ route('app.hr.employees.index') }}">Cancel</a></div>
<form method="post" class="card" action="{{ route('app.hr.employees.store') }}">@csrf @include('hr::employees.fields')<div class="toolbar" style="margin-top:14px"><button class="btn primary" type="submit">Save employee</button></div></form>
@endsection
