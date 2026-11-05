@extends('academic::layout')
@section('title', 'Payroll')
@section('content')
<div class="head"><h1>Payroll</h1></div>
@include('hr::partials.tabs')
@can('academic.payroll.process')<form method="post" action="{{ route('app.hr.payroll.process') }}" class="filters">@csrf<label class="field">Month<input type="month" name="period" value="{{ $next }}" max="{{ $next }}"></label><button class="btn primary" type="submit">Process payroll</button></form>
<p class="small muted">Processing works out every payslip from salary, attendance, one-offs and the statutory rules. Another person approves it, which posts the salary journal to the books and locks the month.</p>@endcan
<section class="card"><div class="table-wrap"><table><thead><tr><th>Month</th><th>Status</th><th class="num">Employees</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Employer PF and ESI</th><th class="num">Net pay</th></tr></thead><tbody>
@forelse ($runs as $r)<tr><td><a href="{{ route('app.hr.payroll.show', $r) }}">{{ $r->label() }}</a></td><td>{{ $r->status }}</td><td class="num">{{ $r->employees }}</td><td class="num">{{ $money($r->gross) }}</td><td class="num">{{ $money($r->deductions) }}</td><td class="num">{{ $money($r->employer_cost) }}</td><td class="num"><b>{{ $money($r->net) }}</b></td></tr>
@empty<tr><td colspan="7" class="empty">No payroll yet.</td></tr>@endforelse</tbody></table></div></section>
@endsection
