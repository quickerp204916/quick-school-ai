@extends('platform::layout')
@section('title', 'Institutions')
@section('content')
<div class="head"><h1>Institutions</h1>@can('platform.tenants.manage')<a class="btn primary" href="{{ route('platform.tenants.create') }}">Onboard institution</a>@endcan</div>

<form class="filters" method="get">
    <label class="field grow">Search<input type="search" name="q" value="{{ request('q') }}" placeholder="Name, code, contact email or city"></label>
    <label class="field">Status<select name="status"><option value="">Any status</option>
        @foreach (\Modules\Platform\Models\Tenant::STATUSES as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach</select></label>
    <label class="field">Plan<select name="plan"><option value="">Any plan</option>
        @foreach ($plans as $p)<option value="{{ $p->id }}" @selected(request('plan') === $p->id)>{{ $p->name }}</option>@endforeach</select></label>
    <button class="btn" type="submit">Filter</button>
</form>

<section class="card">
    <div class="table-wrap"><table>
        <thead><tr><th>Institution</th><th>Type</th><th>Plan</th><th>Status</th><th>Renews or trial ends</th><th>Domain</th></tr></thead>
        <tbody>
        @forelse ($tenants as $t)
            @php($s = $t->subscription)
            <tr>
                <td><a href="{{ route('platform.tenants.show', $t) }}"><b>{{ $t->name }}</b></a><div class="small muted">{{ $t->code }} · {{ $t->city ?? 'No city' }}</div></td>
                <td>{{ config('platform.institution_types.'.$t->type) }}</td>
                <td>{{ $s?->plan?->name ?? '—' }}<div class="small muted">{{ $s?->billing_cycle }}</div></td>
                <td><span class="chip {{ $t->status }}">{{ str_replace('_', ' ', $t->status) }}</span></td>
                <td class="small">{{ $s ? ($s->status === 'trialing' ? 'Trial ends '.$s->trial_ends_at->format('j M Y') : 'Period ends '.$s->current_period_end->format('j M Y')) : '—' }}</td>
                <td class="small">{{ $t->primaryDomain() }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No institutions match these filters.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="pager small">{{ $tenants->total() }} institutions @if ($tenants->hasPages()) · {!! $tenants->links('pagination::simple-default') !!} @endif</div>
</section>
@endsection
