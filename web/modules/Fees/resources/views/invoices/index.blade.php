@extends('academic::layout')
@section('title', 'Fee invoices')
@section('content')
<div class="head"><h1>Fee invoices</h1></div>
@include('fees::partials.tabs')
<form method="get" class="filters"><label class="field">Show<select name="status">@foreach (['due' => 'Due (open or part paid)', 'overdue' => 'Overdue', 'draft' => 'Scheduled', 'paid' => 'Paid', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select></label>
    <label class="field">Due from<input type="date" name="from" value="{{ request('from') }}"></label><label class="field">to<input type="date" name="to" value="{{ request('to') }}"></label><button class="btn" type="submit">Show</button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Student</th><th>Period</th><th>Due</th><th class="num">Total</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>
@forelse ($list as $i)
    <tr><td><a href="{{ route('app.fees.invoices.show', $i) }}">{{ $i->number ?? 'Scheduled' }}</a></td><td><a href="{{ route('app.fees.students.show', $i->student) }}">{{ $i->student->fullName() }}</a></td><td>{{ $i->period }}</td>
    <td>{{ $i->due_date->format('j M Y') }}</td><td class="num">{{ $money($i->total()) }}</td><td class="num">{{ $money($i->balance()) }}</td><td>@include('fees::partials.status', ['s' => $i->status, 'overdue' => $i->isOverdue()])</td></tr>
@empty<tr><td colspan="7" class="empty">No invoices.</td></tr>@endforelse
</tbody></table></div>@include('academic::partials.pager', ['p' => $list])</section>
@endsection
