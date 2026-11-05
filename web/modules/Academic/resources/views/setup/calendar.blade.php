@extends('academic::layout')
@section('title', 'Bell schedule and holidays')
@section('content')
<div class="head"><h1>Bell schedule and holidays</h1></div>
@include('academic::setup.nav')
@foreach ($branches as $b)
<section class="card"><h2>Periods · {{ $b->name }}</h2>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>Name</th><th>Time</th><th>Break</th><th></th></tr></thead><tbody>
    @forelse ($periods[$b->id] ?? [] as $p)
        <tr><td>{{ $p->sequence }}</td><td>{{ $p->name }}</td><td>{{ $p->timeRange() }}</td><td>{{ $p->is_break ? 'Yes' : '' }}</td>
            <td class="toolbar"><details><summary class="small">Edit</summary><form method="post" action="{{ route('app.setup.periods.update', $p) }}" class="filters" style="margin-top:6px">@csrf @method('put')
                <label class="field">Name<input type="text" name="name" value="{{ $p->name }}" required></label>
                <label class="field">Order<input type="number" name="sequence" value="{{ $p->sequence }}" min="1" max="20"></label>
                <label class="field">Starts<input type="time" name="starts_at" value="{{ substr($p->starts_at, 0, 5) }}" required></label>
                <label class="field">Ends<input type="time" name="ends_at" value="{{ substr($p->ends_at, 0, 5) }}" required></label>
                <input type="hidden" name="is_break" value="0"><label class="check"><input type="checkbox" name="is_break" value="1" @checked($p->is_break)> Break</label>
                <button class="btn sm" type="submit">Save</button></form></details>
                <form class="inline" method="post" action="{{ route('app.setup.periods.destroy', $p) }}">@csrf @method('delete')<button class="btn sm danger" type="submit">Delete</button></form></td></tr>
    @empty<tr><td colspan="5" class="empty">No periods yet. Add them to build the timetable.</td></tr>@endforelse
    </tbody></table></div>
    <form method="post" action="{{ route('app.setup.periods.store') }}" class="filters" style="margin-top:10px">@csrf
        <input type="hidden" name="branch_id" value="{{ $b->id }}">
        <label class="field">Name<input type="text" name="name" required placeholder="Period {{ count($periods[$b->id] ?? []) + 1 }}"></label>
        <label class="field">Order<input type="number" name="sequence" value="{{ count($periods[$b->id] ?? []) + 1 }}" min="1" max="20"></label>
        <label class="field">Starts<input type="time" name="starts_at" required></label>
        <label class="field">Ends<input type="time" name="ends_at" required></label>
        <input type="hidden" name="is_break" value="0"><label class="check"><input type="checkbox" name="is_break" value="1"> Break</label>
        <button class="btn" type="submit">Add period</button></form>
</section>
@endforeach
<section class="card"><h2>Holidays</h2>
    <p class="small muted" style="margin-top:-6px">Attendance cannot be marked on holidays.</p>
    <div class="table-wrap"><table><thead><tr><th>Holiday</th><th>From</th><th>To</th><th>Branch</th><th></th></tr></thead><tbody>
    @forelse ($holidays as $h)
        <tr><td>{{ $h->name }}</td><td>{{ $h->starts_on->format('D j M Y') }}</td><td>{{ $h->ends_on->format('D j M Y') }}</td><td>{{ $branches->firstWhere('id', $h->branch_id)?->name ?? 'All branches' }}</td>
            <td><form class="inline" method="post" action="{{ route('app.setup.holidays.destroy', $h) }}">@csrf @method('delete')<button class="btn sm danger" type="submit">Remove</button></form></td></tr>
    @empty<tr><td colspan="5" class="empty">No holidays added.</td></tr>@endforelse</tbody></table></div>
    <form method="post" action="{{ route('app.setup.holidays.store') }}" class="filters" style="margin-top:10px">@csrf
        <label class="field grow">Name<input type="text" name="name" required placeholder="Diwali break"></label>
        <label class="field">From<input type="date" name="starts_on" required></label>
        <label class="field">To<input type="date" name="ends_on" required></label>
        <label class="field">Branch<select name="branch_id"><option value="">All branches</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></label>
        <button class="btn" type="submit">Add holiday</button></form>
</section>
@endsection
