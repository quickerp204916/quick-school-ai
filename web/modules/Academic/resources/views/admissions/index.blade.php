@extends('academic::layout')
@section('title', 'Admissions')
@section('content')
<div class="head"><div><h1>Admissions</h1><p class="muted" style="margin:4px 0 0">{{ $funnel['total'] }} enquiries and applications in {{ $session->name }} · {{ $funnel['conversion'] }}% admitted</p></div>
    <a class="btn primary" href="{{ route('app.admissions.create', ['session' => $session->id]) }}">New enquiry</a></div>
<div class="toolbar">
    @foreach ($funnel['stages'] as $stage => $n)
        <a class="chip {{ request('stage') === $stage ? 'current' : $stage }}" href="{{ route('app.admissions.index', ['session' => $session->id, 'stage' => request('stage') === $stage ? null : $stage]) }}">{{ str_replace('_', ' ', $stage) }} · {{ $n }}</a>
    @endforeach
</div>
<div class="filters">
    @include('academic::partials.session-filter')
    <form method="get" class="filters">
        <input type="hidden" name="session" value="{{ $session->id }}"><input type="hidden" name="stage" value="{{ request('stage') }}">
        <label class="field grow">Search<input type="search" name="q" value="{{ request('q') }}" placeholder="Name, number or phone"></label>
        <label class="field">{{ $t('level') }}<select name="level"><option value="">All</option>@foreach ($levels as $l)<option value="{{ $l->id }}" @selected(request('level') === $l->id)>{{ $l->name }}</option>@endforeach</select></label>
        <label class="check"><input type="checkbox" name="follow_up" value="1" @checked(request()->boolean('follow_up'))> Follow-up due</label>
        <button class="btn" type="submit">Filter</button>
    </form>
</div>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Number</th><th>Applicant</th><th>For</th><th>Contact</th><th>Source</th><th>Stage</th><th>Follow up</th></tr></thead><tbody>
@forelse ($apps as $a)
    <tr><td><a href="{{ route('app.admissions.show', $a) }}">{{ $a->number }}</a></td><td><b>{{ $a->fullName() }}</b><div class="small muted">{{ $a->created_at->format('j M') }}</div></td>
        <td>{{ $a->level->name }}<div class="small muted">{{ $a->branch->name }}</div></td><td>{{ $a->phone }}</td><td class="small">{{ config('academic.admission.sources.'.$a->source) }}</td>
        <td><span class="chip {{ $a->stage }}">{{ str_replace('_', ' ', $a->stage) }}</span></td>
        <td class="{{ $a->follow_up_on && $a->follow_up_on->lte(today()) && $a->isOpen() ? 'chip warning' : '' }}">{{ $a->follow_up_on?->format('j M') }}</td></tr>
@empty<tr><td colspan="7" class="empty">No enquiries match.</td></tr>@endforelse
</tbody></table></div>@include('academic::partials.pager', ['p' => $apps])</section>
@endsection
