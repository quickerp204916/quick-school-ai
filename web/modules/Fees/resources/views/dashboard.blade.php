@extends('academic::layout')
@section('title', 'Fees')
@section('content')
<div class="head"><h1>Fees</h1>
    <div class="toolbar">@can('academic.fees.collect')<a class="btn primary" href="{{ route('app.fees.students.index') }}">Collect fees</a>@endcan
    @can('academic.fees.configure')<form method="post" action="{{ route('app.fees.run') }}" class="inline">@csrf<button class="btn" type="submit">Run today's fee jobs</button></form>@endcan</div></div>
@include('fees::partials.tabs')
<div class="grid">
    <div class="card kpi"><div class="label">Collected today</div><div class="value">{{ $money($today) }}</div></div>
    <div class="card kpi"><div class="label">Collected this month</div><div class="value">{{ $money($month) }}</div></div>
    <div class="card kpi"><div class="label">Total due</div><div class="value">{{ $money($due) }}</div></div>
    <div class="card kpi"><div class="label">Overdue</div><div class="value">{{ $money($overdue) }}</div><div class="small muted">{{ $overdueStudents }} student(s)</div></div>
</div>
<div class="cols">
    <section class="card"><h2>This month by mode</h2>
        @forelse ($byMode as $mode => $amt)<div class="kv"><span>{{ \Modules\Fees\Models\FeePayment::MODES[$mode] }}</span><span class="num">{{ $money($amt) }}</span></div>@empty<p class="empty">No collections yet this month.</p>@endforelse
        @if ($pending)<p><a href="{{ route('app.fees.approvals') }}">{{ $pending }} concession or refund item(s) waiting</a></p>@endif
    </section>
    <section class="card"><h2>Latest receipts</h2><div class="table-wrap"><table><tbody>
        @forelse ($recent as $p)<tr><td>{{ $p->date->format('j M') }}</td><td><a href="{{ route('app.fees.payments.show', $p) }}">{{ $p->number }}</a></td><td>{{ $p->student->fullName() }}</td><td>{{ \Modules\Fees\Models\FeePayment::MODES[$p->mode] }}</td><td class="num">{{ $money($p->amount) }}</td></tr>
        @empty<tr><td class="empty">No receipts yet.</td></tr>@endforelse</tbody></table></div></section>
</div>
@endsection
