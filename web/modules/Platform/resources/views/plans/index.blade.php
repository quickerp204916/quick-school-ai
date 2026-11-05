@extends('platform::layout')
@section('title', 'Plans')
@section('content')
<div class="head"><div><h1>Plans</h1><p class="muted" style="margin:4px 0 0">Prices exclude GST. Each subscription keeps the price it was sold at until its plan is changed.</p></div>
    @can('platform.billing')<a class="btn primary" href="{{ route('platform.plans.create') }}">New plan</a>@endcan</div>
<section class="card"><div class="table-wrap"><table>
    <thead><tr><th>Feature</th>@foreach ($plans as $p)<th>{{ $p->name }} @if (! $p->is_active)<span class="chip">inactive</span>@elseif (! $p->is_public)<span class="chip">private</span>@endif</th>@endforeach</tr></thead>
    <tbody>
        <tr><td><b>Monthly</b></td>@foreach ($plans as $p)<td class="num" style="text-align:left">{{ \App\Core\Support\Money::of($p->price_monthly)->format() }}</td>@endforeach</tr>
        <tr><td><b>Yearly</b></td>@foreach ($plans as $p)<td class="num" style="text-align:left">{{ \App\Core\Support\Money::of($p->price_yearly)->format() }}</td>@endforeach</tr>
        <tr><td>Trial</td>@foreach ($plans as $p)<td>{{ $p->trial_days }} days</td>@endforeach</tr>
        <tr><td>Live subscriptions</td>@foreach ($plans as $p)<td>{{ $p->live_subscriptions }}</td>@endforeach</tr>
        @foreach ($catalogue as $key => $def)
            <tr><td>{{ $def['label'] }}</td>@foreach ($plans as $p)@php($v = $p->featureMap()[$key] ?? $def['default'])
                <td>@if ($def['type'] === 'bool'){{ $v ? 'Yes' : '—' }}@else{{ $v === null ? 'Unlimited' : number_format($v) }}@endif</td>@endforeach</tr>
        @endforeach
        @can('platform.billing')<tr><td></td>@foreach ($plans as $p)<td><a class="btn sm" href="{{ route('platform.plans.edit', $p) }}">Edit</a></td>@endforeach</tr>@endcan
    </tbody>
</table></div></section>
@endsection
