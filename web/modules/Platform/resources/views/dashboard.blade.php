@extends('platform::layout')
@section('title', 'Dashboard')
@section('content')
<div class="head"><div><h1>Platform overview</h1><p class="muted" style="margin:4px 0 0">{{ now()->format('l, j F Y') }}</p></div>
    @can('platform.tenants.manage')<a class="btn primary" href="{{ route('platform.tenants.create') }}">Onboard institution</a>@endcan</div>

<div class="grid">
    <div class="card kpi"><div class="label">Institutions</div><div class="value">{{ number_format($totalTenants) }}</div>
        <div class="small muted">{{ $byStatus['active'] ?? 0 }} active · {{ $byStatus['trial'] ?? 0 }} on trial · {{ ($byStatus['past_due'] ?? 0) }} past due · {{ $byStatus['suspended'] ?? 0 }} suspended</div></div>
    <div class="card kpi"><div class="label">Monthly recurring revenue</div><div class="value">{{ $mrr->format() }}</div><div class="small muted">Before GST, from active and past-due subscriptions</div></div>
    <div class="card kpi"><div class="label">Overdue invoices</div><div class="value" style="color:var(--bad)">{{ $overdueTotal->format() }}</div><div class="small muted">{{ $overdueCount }} invoices past their due date</div></div>
    <div class="card kpi"><div class="label">Collected this month</div><div class="value">{{ $collected->format() }}</div><div class="small muted">AI credits used: {{ number_format($aiUsed) }}</div></div>
</div>

<div class="cols">
    <section class="card"><h2>Trials ending in the next 7 days</h2>
        @if ($trialsEnding->isEmpty())<p class="muted">No trials end this week.</p>@else
        <div class="table-wrap"><table><thead><tr><th>Institution</th><th>Plan</th><th>Trial ends</th></tr></thead><tbody>
        @foreach ($trialsEnding as $s)
            <tr><td><a href="{{ route('platform.tenants.show', $s->tenant) }}">{{ $s->tenant->name }}</a></td><td>{{ $s->plan->name }}</td><td>{{ $s->trial_ends_at->format('j M') }}</td></tr>
        @endforeach</tbody></table></div>@endif
    </section>
    <section class="card"><h2>Newest institutions</h2>
        @if ($newTenants->isEmpty())<p class="muted">No institutions yet. Onboard the first one to start.</p>@else
        <div class="table-wrap"><table><thead><tr><th>Institution</th><th>Plan</th><th>Status</th></tr></thead><tbody>
        @foreach ($newTenants as $t)
            <tr><td><a href="{{ route('platform.tenants.show', $t) }}">{{ $t->name }}</a><div class="small muted">{{ $t->created_at->format('j M Y') }}</div></td>
                <td>{{ $t->subscription?->plan?->name ?? '—' }}</td><td><span class="chip {{ $t->status }}">{{ str_replace('_', ' ', $t->status) }}</span></td></tr>
        @endforeach</tbody></table></div>@endif
    </section>
</div>

<section class="card"><h2>Recent activity</h2>
    <div class="table-wrap"><table><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Institution</th></tr></thead><tbody>
    @forelse ($recentAudit as $log)
        <tr><td class="small">{{ $log->created_at->format('j M, H:i') }}</td><td>{{ $log->admin?->name ?? 'System' }}</td><td><code>{{ $log->action }}</code></td>
            <td>@if ($log->tenant)<a href="{{ route('platform.tenants.show', $log->tenant) }}">{{ $log->tenant->name }}</a>@else — @endif</td></tr>
    @empty <tr><td colspan="4" class="muted">Nothing recorded yet.</td></tr> @endforelse
    </tbody></table></div>
</section>
@endsection
