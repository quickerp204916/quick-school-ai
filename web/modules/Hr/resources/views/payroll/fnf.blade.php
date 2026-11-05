@extends('academic::layout')
@section('title', 'Full and final · '.$f->staff->name)
@section('content')
<div class="head"><div><h1>Full and final · {{ $f->staff->name }}</h1><p class="muted" style="margin:4px 0 0">Relieved {{ $f->relieved_on->format('j M Y') }} · {{ $f->service_years }} years of service · {{ $f->status }}</p></div>
<div class="toolbar">@if ($f->status === 'draft')@can('academic.payroll.approve')<form method="post" action="{{ route('app.hr.fnf.approve', $f) }}" class="inline">@csrf<button class="btn primary">Approve and post</button></form>@endcan @endif<a class="btn" href="{{ route('app.hr.employees.show', $f->staff) }}">Employee</a></div></div>
<section class="card"><div class="table-wrap"><table><tbody>
<tr><td>Salary for {{ $f->salary_days }} day(s) of the last month{{ $f->workings['last_month_covered_by_payroll'] ? ' (paid in payroll)' : '' }}</td><td class="num">{{ $money($f->salary) }}</td></tr>
<tr><td>Leave encashment, {{ $f->encash_days }} day(s) at (basic + DA) ÷ 26</td><td class="num">{{ $money($f->leave_encashment) }}</td></tr>
<tr><td>Gratuity{{ $f->gratuity ? ': 15/26 × (basic + DA) × '.$f->workings['gratuity_years'].' years' : ' (under 5 years of service)' }}</td><td class="num">{{ $money($f->gratuity) }}</td></tr>
<tr><td>Less notice shortfall ({{ $f->workings['notice_shortfall_days'] }} days)</td><td class="num">−{{ $money($f->notice_recovery) }}</td></tr>
<tr><td>Less advance recovery</td><td class="num">−{{ $money($f->advance_recovery) }}</td></tr><tr><td>Less other deductions</td><td class="num">−{{ $money($f->other_deductions) }}</td></tr><tr><td>Less TDS</td><td class="num">−{{ $money($f->tds) }}</td></tr>
<tr><td><b>{{ $f->net >= 0 ? 'Payable to the employee' : 'Recoverable from the employee' }}</b></td><td class="num"><b>{{ $money(abs($f->net)) }}</b></td></tr></tbody></table></div></section>
@if ($f->status === 'approved' && $f->net > 0)@can('academic.payroll.approve')<form method="post" action="{{ route('app.hr.fnf.paid', $f) }}" class="card form">@csrf<label class="field">Payment UTR<input name="reference" required></label><div><button class="btn primary">Mark paid and post</button></div></form>@endcan @endif
@endsection
