@extends('platform::layout')
@section('title', $tenant->name)
@section('content')
@php($s = $tenant->subscription)
<div class="head">
    <div><h1>{{ $tenant->name }}</h1>
        <p class="muted" style="margin:4px 0 0">{{ $tenant->primaryDomain() }} · {{ config('platform.institution_types.'.$tenant->type) }} · <span class="chip {{ $tenant->status }}">{{ str_replace('_', ' ', $tenant->status) }}</span>
        @if ($tenant->status === 'suspended') <span class="small">({{ $tenant->suspension_type }}: {{ $tenant->suspension_reason }})</span>@endif</p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @can('platform.tenants.manage')<a class="btn" href="{{ route('platform.tenants.edit', $tenant) }}">Edit details</a>@endcan
        @can('platform.tenants.manage')
            @if ($tenant->status === 'suspended')
                <form class="inline" method="post" action="{{ route('platform.tenants.reactivate', $tenant) }}">@csrf<button class="btn primary" type="submit">Reactivate</button></form>
            @endif
        @endcan
    </div>
</div>

<div class="cols">
    <section class="card"><h2>Profile</h2>
        <dl class="kv">
            <dt>Code</dt><dd>{{ $tenant->code }}</dd>
            <dt>Legal name</dt><dd>{{ $tenant->legal_name ?: '—' }}</dd>
            <dt>Owner</dt><dd>{{ $owner?->user?->name ?? '—' }} · {{ $owner?->user?->email }}</dd>
            <dt>Contact</dt><dd>{{ $tenant->contact_name }} · {{ $tenant->contact_email }} {{ $tenant->contact_phone ? '· '.$tenant->contact_phone : '' }}</dd>
            <dt>GSTIN</dt><dd>{{ $tenant->billing_gstin ?: 'Not registered' }}</dd>
            <dt>GST state</dt><dd>{{ $tenant->billing_state_code ? $tenant->billing_state_code.' · '.$stateName : 'Not known' }}</dd>
            <dt>Branches</dt><dd>@foreach ($branches as $b){{ $b->name }}{{ $b->is_primary ? ' (main)' : '' }}@if (! $loop->last), @endif @endforeach</dd>
            <dt>Onboarded</dt><dd>{{ $tenant->created_at->format('j M Y') }}</dd>
        </dl>
    </section>

    <section class="card"><h2>Subscription</h2>
        @if ($s)
            <dl class="kv">
                <dt>Plan</dt><dd><b>{{ $s->plan->name }}</b> · {{ $s->billing_cycle }} · {{ \App\Core\Support\Money::of($s->price)->format() }} + GST</dd>
                <dt>Status</dt><dd><span class="chip {{ $s->status }}">{{ str_replace('_', ' ', $s->status) }}</span>
                    @if ($s->cancel_at_period_end) <span class="chip cancelled">ends {{ $s->current_period_end->format('j M Y') }}</span>@endif
                    @if ($s->grace_until) <span class="small muted">grace until {{ $s->grace_until->format('j M') }}</span>@endif</dd>
                <dt>{{ $s->status === 'trialing' ? 'Trial ends' : 'Current period' }}</dt>
                <dd>{{ $s->status === 'trialing' ? $s->trial_ends_at->format('j M Y') : $s->current_period_start->format('j M Y').' to '.$s->current_period_end->format('j M Y') }}</dd>
            </dl>
            @can('platform.billing')
                @if ($s->status !== 'cancelled')
                <form method="post" action="{{ route('platform.tenants.subscription.change', $tenant) }}" class="filters" style="margin-top:14px">@csrf
                    <label class="field">Change plan<select name="plan_id">@foreach ($plans as $p)<option value="{{ $p->id }}" @selected($p->id === $s->plan_id)>{{ $p->name }}</option>@endforeach</select></label>
                    <label class="field">Cycle<select name="billing_cycle"><option value="monthly" @selected($s->billing_cycle === 'monthly')>Monthly</option><option value="yearly" @selected($s->billing_cycle === 'yearly')>Yearly</option></select></label>
                    <button class="btn" type="submit">Change</button>
                </form>
                <p class="small muted">Price changes apply from the next invoice. There is no proration.</p>
                @if ($s->cancel_at_period_end)
                    <form method="post" action="{{ route('platform.tenants.subscription.resume', $tenant) }}">@csrf<button class="btn sm" type="submit">Keep subscription</button></form>
                @else
                    <details><summary class="small">Cancel subscription</summary>
                        <form method="post" action="{{ route('platform.tenants.subscription.cancel', $tenant) }}" class="filters" style="margin-top:8px">@csrf
                            <label class="field">When<select name="when"><option value="period_end">At period end</option><option value="now">Now (suspends access)</option></select></label>
                            <label class="field grow">Reason<input type="text" name="reason" required minlength="5" maxlength="300"></label>
                            <button class="btn danger" type="submit">Cancel subscription</button>
                        </form></details>
                @endif
                @endif
            @endcan
        @else
            <p class="muted">No subscription.</p>
        @endif
    </section>
</div>

<div class="cols">
    <section class="card"><h2>Features and limits</h2>
        <div class="table-wrap"><table><thead><tr><th>Feature</th><th>Value</th><th>From</th><th></th></tr></thead><tbody>
        @foreach ($features as $key => $f)
            @php($override = $tenant->featureOverrides->firstWhere('feature', $key))
            <tr><td>{{ $f['label'] }}</td>
                <td>@if ($f['type'] === 'bool'){{ $f['value'] ? 'On' : 'Off' }}@else{{ $f['value'] === null ? 'Unlimited' : number_format($f['value']) }}@endif</td>
                <td><span class="chip {{ $f['source'] === 'override' ? 'override' : '' }}">{{ $f['source'] }}</span>
                    @if ($override?->expires_at)<div class="small muted">until {{ $override->expires_at->format('j M Y') }}</div>@endif</td>
                <td>@if ($override && $f['source'] === 'override')
                    @can('platform.tenants.manage')<form class="inline" method="post" action="{{ route('platform.tenants.overrides.destroy', [$tenant, $override]) }}">@csrf @method('delete')<button class="btn sm" type="submit">Remove</button></form>@endcan
                @endif</td></tr>
        @endforeach</tbody></table></div>
        @can('platform.tenants.manage')
        <form method="post" action="{{ route('platform.tenants.overrides.store', $tenant) }}" class="filters" style="margin-top:12px">@csrf
            <label class="field">Feature<select name="feature">@foreach ($features as $key => $f)<option value="{{ $key }}">{{ $f['label'] }}</option>@endforeach</select></label>
            <label class="field">Value<span class="hint">on, off, a number or unlimited</span><input type="text" name="value" maxlength="20"></label>
            <label class="field">Until<input type="date" name="expires_at"></label>
            <label class="field grow">Reason<input type="text" name="reason" required minlength="5" maxlength="300"></label>
            <button class="btn" type="submit">Set override</button>
        </form>
        @endcan
    </section>

    <section class="card"><h2>AI credits · balance {{ number_format($credits) }}</h2>
        <div class="table-wrap"><table><thead><tr><th>Date</th><th>Kind</th><th class="num">Credits</th><th>Note</th></tr></thead><tbody>
        @forelse ($creditEntries as $e)
            <tr><td class="small">{{ $e->created_at->format('j M, H:i') }}</td><td>{{ str_replace('_', ' ', $e->kind) }}</td><td class="num">{{ $e->amount > 0 ? '+' : '' }}{{ number_format($e->amount) }}</td><td class="small">{{ $e->note }}</td></tr>
        @empty <tr><td colspan="4" class="muted">No credit activity yet.</td></tr> @endforelse
        </tbody></table></div>
        @can('platform.billing')
        <form method="post" action="{{ route('platform.tenants.credits.store', $tenant) }}" class="filters" style="margin-top:12px">@csrf
            <label class="field">Kind<select name="kind"><option value="top_up">Top-up</option><option value="adjustment">Adjustment (+ or −)</option></select></label>
            <label class="field">Credits<input type="number" name="amount" required step="1"></label>
            <label class="field grow">Note<input type="text" name="note" required maxlength="300" placeholder="Paid top-up, invoice QSA/2026-27/00042"></label>
            <button class="btn" type="submit">Apply</button>
        </form>
        @endcan
    </section>
</div>

@can('platform.billing')
<section class="card"><h2>Invoices</h2>
    <div class="table-wrap"><table><thead><tr><th>Number</th><th>Period</th><th>Due</th><th class="num">Total</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>
    @forelse ($invoices as $i)
        <tr><td><a href="{{ route('platform.invoices.show', $i) }}">{{ $i->number }}</a></td><td class="small">{{ $i->period_start?->format('j M Y') }} to {{ $i->period_end?->format('j M Y') }}</td>
            <td>{{ $i->due_date->format('j M Y') }}</td><td class="num">{{ \App\Core\Support\Money::of($i->total)->format() }}</td><td class="num">{{ $i->balance()->format() }}</td>
            <td><span class="chip {{ $i->displayStatus() }}">{{ $i->displayStatus() }}</span></td></tr>
    @empty <tr><td colspan="6" class="muted">No invoices yet. The first one is issued when the trial ends.</td></tr> @endforelse
    </tbody></table></div>
</section>
@endcan

<div class="cols">
    @can('platform.support')
    <section class="card"><h2>Support access</h2>
        @if ($activeSupport)
            <p>You have access until <b>{{ $activeSupport->expires_at->format('H:i') }}</b> for: {{ $activeSupport->reason }}</p>
            <form method="post" action="{{ route('platform.support.end', $activeSupport) }}">@csrf<button class="btn" type="submit">End session now</button></form>
        @else
            <p class="small muted">Opens time-limited access to this institution's data. The owner sees the reason and time in their audit log.</p>
            <form method="post" action="{{ route('platform.support.store', $tenant) }}" class="filters">@csrf
                <label class="field grow">Reason<input type="text" name="reason" required minlength="10" maxlength="500" placeholder="Ticket #1234: fee receipts not printing"></label>
                <label class="field">Minutes<select name="minutes">@foreach ([15, 30, 60, 120] as $m)<option value="{{ $m }}" @selected($m === 30)>{{ $m }}</option>@endforeach</select></label>
                <button class="btn" type="submit">Start session</button>
            </form>
        @endif
    </section>
    @endcan
    <section class="card"><h2>Institution audit log</h2>
        <div class="table-wrap"><table><thead><tr><th>When</th><th>Actor</th><th>Action</th></tr></thead><tbody>
        @forelse ($tenantAudit as $a)
            <tr><td class="small">{{ $a->created_at->format('j M, H:i') }}</td><td class="small">{{ str_replace('_', ' ', $a->actor_type) }}</td><td><code>{{ $a->action }}</code></td></tr>
        @empty <tr><td colspan="3" class="muted">No entries.</td></tr> @endforelse
        </tbody></table></div>
    </section>
</div>

@can('platform.tenants.manage')
<section class="card danger-zone"><h2>Suspend or archive</h2>
    <div class="cols">
        @if ($tenant->status !== 'suspended' && $tenant->status !== 'archived')
        <form method="post" action="{{ route('platform.tenants.suspend', $tenant) }}" class="filters">@csrf
            <label class="field grow">Reason for suspending<input type="text" name="reason" required minlength="5" maxlength="500"></label>
            <button class="btn danger" type="submit">Suspend</button>
        </form>
        @endif
        @if ($tenant->status !== 'archived')
        <form method="post" action="{{ route('platform.tenants.archive', $tenant) }}" class="filters">@csrf
            <label class="field grow">Reason for archiving<input type="text" name="reason" required minlength="5" maxlength="500"></label>
            <label class="field">Type “{{ $tenant->code }}” to confirm<input type="text" name="confirm_code" required></label>
            <button class="btn danger" type="submit">Archive</button>
        </form>
        @endif
    </div>
    <p class="small muted">Suspending blocks sign-in and keeps all data. Archiving also cancels the subscription. Data is never deleted from here.</p>
</section>
@endcan
@endsection
