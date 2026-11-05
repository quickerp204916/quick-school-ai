@extends('academic::layout')
@section('title', 'Staff')
@section('content')
<div class="head"><div><h1>Staff</h1><p class="muted" style="margin:4px 0 0">{{ $staff->count() }} shown @if ($limit !== null)· plan allows {{ $limit }} staff accounts @endif</p></div></div>
<form method="get" class="filters">
    <label class="field grow">Search<input type="search" name="q" value="{{ request('q') }}" placeholder="Name or employee code"></label>
    <label class="field">Status<select name="status">@foreach (['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected(request('status', 'active') === $k)>{{ $l }}</option>@endforeach</select></label>
    <button class="btn" type="submit">Filter</button>
</form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Designation</th><th>Branch</th><th>Teaches</th><th>Sign-in</th><th></th></tr></thead><tbody>
@forelse ($staff as $s)
    <tr><td>{{ $s->employee_code }}</td><td><b>{{ $s->name }}</b><div class="small muted">{{ $s->email }} {{ $s->phone }}</div></td><td>{{ $s->designation }}<div class="small muted">{{ $s->department }}</div></td>
        <td>{{ $s->branch?->name }}</td><td>{{ $s->is_teaching ? 'Yes' : 'No' }}</td>
        <td>@if ($s->user_id)<span class="chip active">{{ config('academic.roles.'.($roles[$s->user_id] ?? '')) ?? 'no access' }}</span>@else<span class="muted small">No login</span>@endif</td>
        <td><a class="btn sm" href="{{ route('app.staff.edit', $s) }}">Edit</a></td></tr>
@empty<tr><td colspan="7" class="empty">No staff match.</td></tr>@endforelse
</tbody></table></div></section>
<form class="card" method="post" action="{{ route('app.staff.store') }}">@csrf
    <h2>Add staff</h2>
    @include('academic::staff.fields', ['s' => new \Modules\Academic\Models\StaffMember(['is_teaching' => true, 'status' => 'active']), 'role' => null])
    <p><button class="btn primary" type="submit">Add staff</button></p>
</form>
@endsection
