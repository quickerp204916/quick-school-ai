@extends('academic::layout')
@section('title', 'Homework completion')
@section('content')
<div class="head"><div><h1>Homework completion</h1><p class="muted" style="margin:4px 0 0">Due between {{ \Illuminate\Support\Carbon::parse($from)->format('j M') }} and {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}</p></div>
    <a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">Download CSV</a></div>
<form method="get" class="filters"><label class="field">From<input type="date" name="from" value="{{ $from }}"></label><label class="field">To<input type="date" name="to" value="{{ $to }}"></label><button class="btn" type="submit">Show</button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><th>{{ $t('section') }}</th><th>{{ $t('subject') }}</th><th class="num">Set</th><th class="num">Expected</th><th class="num">Done</th><th class="num">%</th></tr></thead><tbody>
@forelse ($rows as $r)
    <tr><td>{{ $r['section']->label() }}</td><td>{{ $r['subject']->name }}</td><td class="num">{{ $r['set'] }}</td><td class="num">{{ $r['expected'] }}</td><td class="num">{{ $r['done'] }}</td>
        <td class="num" @if ($r['percent'] !== null && $r['percent'] < 60) style="color:var(--bad)" @endif>{{ $r['percent'] ?? '—' }}</td></tr>
@empty<tr><td colspan="6" class="empty">No homework due in this period.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
