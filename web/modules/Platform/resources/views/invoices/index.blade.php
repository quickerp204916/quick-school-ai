@extends('platform::layout')
@section('title', 'Invoices')
@section('content')
<div class="head"><h1>Invoices</h1></div>
<form class="filters" method="get">
    <label class="field grow">Search<input type="search" name="q" value="{{ request('q') }}" placeholder="Invoice number or institution"></label>
    <label class="field">Status<select name="status"><option value="">All</option>@foreach (['open' => 'Open', 'overdue' => 'Overdue', 'paid' => 'Paid', 'void' => 'Void'] as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select></label>
    <button class="btn" type="submit">Filter</button>
</form>
<section class="card"><div class="table-wrap"><table>
    <thead><tr><th>Number</th><th>Institution</th><th>Issued</th><th>Due</th><th class="num">Total</th><th class="num">Balance</th><th>Status</th></tr></thead>
    <tbody>
    @forelse ($invoices as $i)
        <tr><td><a href="{{ route('platform.invoices.show', $i) }}">{{ $i->number }}</a></td>
            <td><a href="{{ route('platform.tenants.show', $i->tenant) }}">{{ $i->tenant->name }}</a></td>
            <td>{{ $i->issue_date->format('j M Y') }}</td><td>{{ $i->due_date->format('j M Y') }}</td>
            <td class="num">{{ \App\Core\Support\Money::of($i->total)->format() }}</td><td class="num">{{ $i->balance()->format() }}</td>
            <td><span class="chip {{ $i->displayStatus() }}">{{ $i->displayStatus() }}</span></td></tr>
    @empty <tr><td colspan="7" class="muted">No invoices match.</td></tr> @endforelse
    </tbody></table></div>
    <div class="pager small">{{ $invoices->total() }} invoices @if ($invoices->hasPages()) · {!! $invoices->links('pagination::simple-default') !!} @endif</div>
</section>
@endsection
