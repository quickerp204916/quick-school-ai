@extends('academic::layout')
@section('title', 'Fee collection')
@section('content')
<div class="head"><h1>Students and fee collection</h1></div>
@include('fees::partials.tabs')
<form method="get" class="filters"><label class="field">Find a student<input name="q" value="{{ request('q') }}" placeholder="Name, admission number or parent's phone" autofocus></label>
    <label class="field">Show<select name="show"><option value="">All active students</option><option value="due" @selected(request('show') === 'due')>With dues</option><option value="no_plan" @selected(request('show') === 'no_plan')>Without a fee plan</option></select></label>
    <button class="btn" type="submit">Search</button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Admission no</th><th>Student</th><th>Class</th><th>Parent</th><th class="num">Due now</th><th></th></tr></thead><tbody>
@forelse ($list as $s)
    <tr><td>{{ $s->admission_no }}</td><td>{{ $s->fullName() }}</td><td>{{ $s->currentEnrollment?->section?->label() }}</td><td>{{ $s->primaryGuardian()?->name }} · {{ $s->primaryGuardian()?->phone }}</td>
    <td class="num">{{ ($balances[$s->id] ?? 0) ? $money($balances[$s->id]) : '—' }}</td><td><a class="btn sm" href="{{ route('app.fees.students.show', $s) }}">Open</a></td></tr>
@empty<tr><td colspan="6" class="empty">No students match.</td></tr>@endforelse
</tbody></table></div>@include('academic::partials.pager', ['p' => $list])</section>
@endsection
