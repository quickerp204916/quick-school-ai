@extends('academic::layout')
@section('title', 'Fee structures')
@section('content')
<div class="head"><h1>Fee structures</h1><a class="btn primary" href="{{ route('app.fees.structures.create') }}">New structure</a></div>
@include('fees::partials.tabs')
<form method="get" class="filters"><label class="field">Session<select name="session">@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected($s->id === $session->id)>{{ $s->name }}</option>@endforeach</select></label><button class="btn" type="submit">Show</button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Name</th><th>Class</th><th>Heads</th><th class="num">Per year</th><th>Late fee</th><th>Active</th></tr></thead><tbody>
@forelse ($list as $s)
    @php($year = $s->items->sum(fn ($i) => $i->amount * ['monthly' => $i->months ? count($i->months) : 12, 'quarterly' => 4, 'half_yearly' => 2, 'annual' => 1, 'one_time' => 1][$i->frequency]))
    <tr><td><a href="{{ route('app.fees.structures.edit', $s) }}">{{ $s->name }}</a></td><td>{{ $s->gradeLevel?->name ?? 'Any' }}</td><td>{{ $s->items->map(fn ($i) => $i->head->name)->join(', ') }}</td>
    <td class="num">{{ $money($year) }}</td><td>{{ \Modules\Fees\Models\FeeStructure::LATE_TYPES[$s->late_fee_type] }}</td><td>{{ $s->is_active ? 'Yes' : 'No' }}</td></tr>
@empty<tr><td colspan="6" class="empty">No fee structures for {{ $session->name }} yet.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
