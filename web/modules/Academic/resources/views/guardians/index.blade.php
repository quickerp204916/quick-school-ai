@extends('academic::layout')
@section('title', 'Guardians')
@section('content')
<div class="head"><div><h1>Guardians</h1><p class="muted" style="margin:4px 0 0">One record per parent, shared by siblings.</p></div></div>
<form method="get" class="filters">
    <label class="field grow">Search<input type="search" name="q" value="{{ request('q') }}" placeholder="Name, mobile or email"></label>
    <label class="check"><input type="checkbox" name="siblings" value="1" @checked(request()->boolean('siblings'))> With more than one child</label>
    <button class="btn" type="submit">Filter</button>
</form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Name</th><th>Mobile</th><th>Email</th><th>Children</th><th>App</th></tr></thead><tbody>
@forelse ($guardians as $g)
    <tr><td><a href="{{ route('app.guardians.show', $g) }}"><b>{{ $g->name }}</b></a><div class="small muted">{{ $g->occupation }}</div></td><td>{{ $g->phone }}</td><td>{{ $g->email }}</td>
        <td>@foreach ($g->students as $st)<a href="{{ route('app.students.show', $st) }}">{{ $st->fullName() }}</a> <span class="small muted">{{ $st->currentEnrollment?->section?->label() }}</span>@if (! $loop->last)<br>@endif @endforeach</td>
        <td>{{ $g->user_id ? 'Invited' : '—' }}</td></tr>
@empty<tr><td colspan="5" class="empty">No guardians match.</td></tr>@endforelse
</tbody></table></div>@include('academic::partials.pager', ['p' => $guardians])</section>
@endsection
