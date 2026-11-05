@extends('academic::layout')
@section('title', 'Payroll '.$run->label())
@section('content')
@php($earnCodes = $slips->flatMap(fn ($p) => array_keys($p->earnings))->unique()->sortBy(fn ($c) => $components[$c]->sort_order ?? 99)->values())
@php($dedCodes = $slips->flatMap(fn ($p) => array_keys($p->deductions))->unique()->sortBy(fn ($c) => $components[$c]->sort_order ?? 99)->values())
<div class="head"><div><h1>Payroll · {{ $run->label() }}</h1><p class="muted" style="margin:4px 0 0">Status: <b>{{ $run->status }}</b>@if ($run->approved_at) · approved {{ $run->approved_at->format('j M Y') }}@endif @if ($run->voucher_id) · <a href="{{ route('app.accounts.vouchers.show', $run->voucher_id) }}">salary journal</a>@endif</p></div>
<div class="toolbar">@if ($run->status === 'processed')@can('academic.payroll.approve')<form method="post" action="{{ route('app.hr.payroll.approve', $run) }}" class="inline">@csrf<button class="btn primary">Approve and post</button></form>@endcan
@can('academic.payroll.process')<form method="post" action="{{ route('app.hr.payroll.reopen', $run) }}" class="inline">@csrf<button class="btn">Reopen</button></form>@endcan @endif
@if ($run->isLocked())@can('academic.payroll.approve')<a class="btn" href="{{ route('app.hr.payroll.bank', $run) }}">Bank file (NEFT)</a>@endcan @endif</div></div>
@include('hr::partials.tabs')
<div class="grid"><div class="card kpi"><div class="label">Employees</div><div class="value">{{ $run->employees }}</div></div><div class="card kpi"><div class="label">Gross</div><div class="value">{{ $money($run->gross) }}</div></div>
<div class="card kpi"><div class="label">Deductions</div><div class="value">{{ $money($run->deductions) }}</div></div><div class="card kpi"><div class="label">Net pay</div><div class="value">{{ $money($run->net) }}</div></div></div>
<nav class="toolbar">@foreach (['pf' => 'PF (ECR)', 'esi' => 'ESI', 'pt' => 'Professional tax', 'tds' => 'TDS'] as $k => $l)<a class="btn sm" href="{{ route('app.hr.payroll.report', [$run, $k]) }}">{{ $l }}</a>@endforeach</nav>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Employee</th><th class="num">Paid days</th>@foreach ($earnCodes as $c)<th class="num">{{ $c }}</th>@endforeach<th class="num">Gross</th>@foreach ($dedCodes as $c)<th class="num">{{ $c }}</th>@endforeach<th class="num">Net</th><th></th></tr></thead><tbody>
@foreach ($slips as $p)<tr><td>{{ $p->staff->name }}</td><td class="num">{{ $p->paid_days }}{{ $p->lop_days ? ' (LOP '.$p->lop_days.')' : '' }}</td>@foreach ($earnCodes as $c)<td class="num">{{ isset($p->earnings[$c]) ? $money($p->earnings[$c]) : '' }}</td>@endforeach
<td class="num"><b>{{ $money($p->gross) }}</b></td>@foreach ($dedCodes as $c)<td class="num">{{ isset($p->deductions[$c]) ? $money($p->deductions[$c]) : '' }}</td>@endforeach<td class="num"><b>{{ $money($p->net) }}</b></td><td><a href="{{ route('app.hr.payslips.show', $p) }}">Payslip</a></td></tr>@endforeach
</tbody></table></div></section>
@if ($run->status === 'approved')@can('academic.payroll.approve')<section class="card"><h2>Salaries paid by the bank</h2><form method="post" action="{{ route('app.hr.payroll.paid', $run) }}" class="form">@csrf
    <label class="field">Bank batch or UTR<input name="reference" required></label><label class="field">Date<input type="date" name="date" value="{{ today()->format('Y-m-d') }}"></label><div><button class="btn primary" type="submit">Mark paid and post</button></div></form></section>@endcan @endif
@endsection
