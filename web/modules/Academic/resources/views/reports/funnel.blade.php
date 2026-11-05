@extends('academic::layout')
@section('title', 'Admissions funnel')
@section('content')
<div class="head"><div><h1>Admissions funnel</h1><p class="muted" style="margin:4px 0 0">{{ $session->name }} · {{ $f['total'] }} enquiries · {{ $f['conversion'] }}% admitted</p></div></div>
@include('academic::partials.session-filter')
<div class="cols">
<section class="card"><h2>By stage</h2>
    @php($max = max(1, max($f['stages'])))
    @foreach ($f['stages'] as $stage => $n)
        <div style="display:grid;grid-template-columns:120px 1fr 40px;gap:10px;align-items:center;margin-bottom:8px"><span class="small">{{ ucfirst(str_replace('_', ' ', $stage)) }}</span>
            <div class="bar"><i style="width:{{ round($n * 100 / $max) }}%"></i></div><span class="num">{{ $n }}</span></div>
    @endforeach</section>
<section class="card"><h2>By source</h2><div class="table-wrap"><table><thead><tr><th>Source</th><th class="num">Enquiries</th><th class="num">Admitted</th><th class="num">Conversion</th></tr></thead><tbody>
    @foreach ($f['sources'] as $src)<tr><td>{{ $src['label'] }}</td><td class="num">{{ $src['total'] }}</td><td class="num">{{ $src['admitted'] }}</td><td class="num">{{ $src['total'] ? round($src['admitted'] * 100 / $src['total'], 1).'%' : '—' }}</td></tr>@endforeach
</tbody></table></div></section>
</div>
@endsection
