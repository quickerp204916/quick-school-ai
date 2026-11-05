@extends('academic::layout')
@section('title', ['sales' => 'Sales invoices', 'purchase' => 'Purchase invoices', 'credit_note' => 'Credit notes', 'debit_note' => 'Debit notes', 'expense' => 'Expenses'][$kind])
@section('content')
@php($label = ['sales' => 'Sales invoice', 'purchase' => 'Purchase invoice', 'credit_note' => 'Credit note', 'debit_note' => 'Debit note', 'expense' => 'Expense'][$kind])
<div class="head"><h1>{{ ['sales' => 'Sales invoices', 'purchase' => 'Purchase invoices', 'credit_note' => 'Credit notes', 'debit_note' => 'Debit notes', 'expense' => 'Expenses'][$kind] }}</h1>
    @can('academic.accounts.post')<a class="btn primary" href="{{ route('app.accounts.invoices.create', ['kind' => $kind]) }}">New {{ strtolower($label) }}</a>@endcan</div>
<nav class="toolbar">@foreach (['sales' => 'Sales', 'purchase' => 'Purchases', 'expense' => 'Expenses', 'credit_note' => 'Credit notes', 'debit_note' => 'Debit notes'] as $k => $l)<a class="btn sm {{ $kind === $k ? 'primary' : '' }}" href="{{ route('app.accounts.invoices.index', ['kind' => $k]) }}">{{ $l }}</a>@endforeach</nav>
@include('accounting::partials.range', ['from' => $from, 'to' => $to])
<section class="card"><div class="table-wrap"><table><thead><tr><th>Date</th><th>Number</th><th>Party</th>@if (in_array($kind, ['purchase', 'expense'], true))<th>Their bill</th>@endif<th class="num">Taxable</th><th class="num">GST</th><th class="num">TDS</th><th class="num">Total</th><th class="num">Due</th><th>Status</th></tr></thead><tbody>
@forelse ($list as $i)
    <tr><td>{{ $i->date->format('j M Y') }}</td><td><a href="{{ route('app.accounts.invoices.show', $i) }}">{{ $i->number ?? 'Draft' }}</a></td><td>{{ $i->party->name }}</td>@if (in_array($kind, ['purchase', 'expense'], true))<td>{{ $i->party_invoice_no }}</td>@endif
        <td class="num">{{ $money($i->subtotal) }}</td><td class="num">{{ $money($i->cgst + $i->sgst + $i->igst) }}</td><td class="num">{{ $i->tds ? $money($i->tds) : '' }}</td><td class="num">{{ $money($i->total) }}</td>
        <td class="num">{{ ($due[$i->id] ?? 0) ? $money($due[$i->id]) : '' }}</td><td><span class="chip {{ ['posted' => 'present', 'pending' => 'warning', 'cancelled' => 'closed', 'draft' => 'draft'][$i->status] }}">{{ $i->status }}</span></td></tr>
@empty<tr><td colspan="10" class="empty">None in this period.</td></tr>@endforelse
</tbody></table></div>@include('academic::partials.pager', ['p' => $list])</section>
@endsection
