@extends('academic::layout')
@section('title', 'Cash flow')
@section('content')
<div class="head"><div><h1>Cash flow statement</h1><p class="muted" style="margin:4px 0 0">Direct method · {{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}</p></div></div>
@include('accounting::partials.report-tabs')
@include('accounting::partials.range', ['from' => $from, 'to' => $to, 'branches' => $branches])
<section class="card"><div class="table-wrap"><table><tbody>
    <tr><td><b>Cash and bank at the start</b></td><td class="num"><b>{{ $drcr($cf['opening']) }}</b></td></tr>
    @foreach (['operating' => 'Operating activities', 'investing' => 'Investing activities', 'financing' => 'Financing activities'] as $k => $l)
        <tr><td colspan="2"><b>{{ $l }}</b></td></tr>
        @forelse ($cf['sections'][$k]['rows'] as $name => $amt)<tr><td style="padding-left:24px">{{ $amt >= 0 ? 'Received from' : 'Paid for' }} {{ strtolower($name) }}</td><td class="num">{{ $money($amt) }}</td></tr>
        @empty<tr><td style="padding-left:24px" class="muted">None</td><td></td></tr>@endforelse
        <tr><td style="padding-left:24px"><i>Net cash from {{ strtolower($l) }}</i></td><td class="num"><i>{{ $money($cf['sections'][$k]['total']) }}</i></td></tr>
    @endforeach
    <tr><td><b>Net change in cash and bank</b></td><td class="num"><b>{{ $money($cf['net']) }}</b></td></tr>
    <tr><td><b>Cash and bank at the end</b></td><td class="num"><b>{{ $drcr($cf['closing']) }}</b></td></tr>
</tbody></table></div>
<p class="small muted">Transfers between cash and bank (contra vouchers) are not cash flows and are left out.</p></section>
@endsection
