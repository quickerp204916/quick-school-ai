@extends('academic::layout')
@section('title', 'Payslip '.$p->run->label())
@section('content')
<div class="head no-print"><h1>Payslip</h1><button class="btn" onclick="window.print()">Print</button></div>
<div class="doc"><header><b style="font-size:18px">{{ \Modules\Academic\Support\Institution::tenant()->name }}</b><div>Payslip for {{ $p->run->label() }}</div></header>
<dl class="kv"><dt>Employee</dt><dd>{{ $p->staff->name }} ({{ $p->staff->employee_code }})</dd><dt>Designation</dt><dd>{{ $p->staff->profile?->designation?->name ?? $p->staff->designation }}</dd>
<dt>Department</dt><dd>{{ $p->staff->profile?->department?->name ?? $p->staff->department }}</dd><dt>Bank account</dt><dd>{{ $p->staff->profile?->maskedAccount() }}</dd><dt>UAN</dt><dd>{{ $p->staff->profile?->uan }}</dd>
<dt>Days</dt><dd>{{ $p->paid_days }} paid of {{ $p->days_in_month }}{{ $p->lop_days ? ' · loss of pay '.$p->lop_days : '' }}{{ $p->overtime_minutes ? ' · overtime '.round($p->overtime_minutes / 60, 1).' h' : '' }}</dd></dl>
<div class="cols" style="margin-top:12px"><table><thead><tr><th>Earnings</th><th class="num">₹</th></tr></thead><tbody>@foreach ($p->earnings as $c => $a)<tr><td>{{ $components[$c]->name ?? $c }}</td><td class="num">{{ $money($a) }}</td></tr>@endforeach<tr><td><b>Gross</b></td><td class="num"><b>{{ $money($p->gross) }}</b></td></tr></tbody></table>
<table><thead><tr><th>Deductions</th><th class="num">₹</th></tr></thead><tbody>@forelse ($p->deductions as $c => $a)<tr><td>{{ $components[$c]->name ?? $c }}</td><td class="num">{{ $money($a) }}</td></tr>@empty<tr><td>None</td><td></td></tr>@endforelse<tr><td><b>Total</b></td><td class="num"><b>{{ $money($p->total_deductions) }}</b></td></tr></tbody></table></div>
<p style="font-size:16px"><b>Net pay: {{ $money($p->net) }}</b> (Rupees {{ \Modules\Academic\Support\Words::number(intdiv($p->net, 100)) }} only)</p>
@if (! empty($p->employer))<p class="small muted">Employer contributions: PF {{ $money($p->employer['PF'] ?? 0) }} (pension {{ $money($p->employer['EPS'] ?? 0) }}) · ESI {{ $money($p->employer['ESI'] ?? 0) }}</p>@endif
@if (isset($p->snapshot['tds']))<p class="small muted">Income tax ({{ $p->snapshot['regime'] }} regime): projected income {{ '₹'.number_format($p->snapshot['tds']['projected_income']) }}, tax for the year {{ '₹'.number_format($p->snapshot['tds']['annual_tax']) }}.</p>@endif
<p class="small muted">Computer-generated payslip.@if (! $p->run->isLocked()) <b>Draft: not yet approved.</b>@endif</p></div>
@endsection
