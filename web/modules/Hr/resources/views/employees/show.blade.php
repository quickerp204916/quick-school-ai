@extends('academic::layout')
@section('title', $s->name)
@section('content')
<div class="head"><div><h1>{{ $s->name }}</h1><p class="muted" style="margin:4px 0 0">{{ $s->employee_code }} · {{ $p->designation?->name ?? $s->designation }} · {{ $p->department?->name ?? $s->department }}@if ($p->relieved_on) · relieved {{ $p->relieved_on->format('j M Y') }}@endif</p></div>
    <a class="btn" href="{{ route('app.hr.employees.index') }}">All employees</a></div>
@include('hr::partials.tabs')
@can('academic.payroll.process')
<section class="card"><h2>Salary</h2>
<div class="table-wrap"><table><thead><tr><th>From</th>@foreach ($components as $c)<th class="num">{{ $c->name }}</th>@endforeach<th class="num">Monthly gross</th></tr></thead><tbody>
@forelse ($structures as $st)<tr><td>{{ $st->effective_from->format('j M Y') }}</td>@foreach ($components as $c)<td class="num">{{ isset($st->components[$c->code]) ? $money($st->components[$c->code]) : '' }}</td>@endforeach<td class="num"><b>{{ $money($st->monthly_gross) }}</b></td></tr>
@empty<tr><td colspan="9" class="empty">No salary set yet. Payroll leaves this employee out until one is added.</td></tr>@endforelse</tbody></table></div>
@can('academic.payroll.configure')<details><summary>{{ $structures->isEmpty() ? 'Set salary' : 'Revise salary from a date' }}</summary><form method="post" action="{{ route('app.hr.employees.salary', $s) }}" class="form">@csrf
    <label class="field">Applies from<input type="date" name="effective_from" value="{{ $structures->isEmpty() ? ($s->joined_on?->format('Y-m-d') ?? today()->startOfMonth()->format('Y-m-d')) : today()->addMonth()->startOfMonth()->format('Y-m-d') }}" required></label>
    @foreach ($components as $c)<label class="field">{{ $c->name }} (₹ a month)<input name="components[{{ $c->code }}]" value="{{ $structures->first() && isset($structures->first()->components[$c->code]) ? \Modules\Accounting\Support\Amount::input($structures->first()->components[$c->code]) : '' }}"></label>@endforeach
    <label class="field wide">Note<input name="note" placeholder="Annual increment"></label><div><button class="btn primary" type="submit">Save salary</button></div></form>
    <p class="small muted">Earlier versions stay as history. PF, ESI, professional tax and income tax are worked out each month from the statutory rules.</p></details>@endcan
<details><summary>Add a one-off to a month's payroll</summary><form method="post" action="{{ route('app.hr.employees.inputs', $s) }}" class="form">@csrf
    <label class="field">Month<input type="month" name="period" value="{{ today()->format('Y-m') }}" required></label>
    <label class="field">What<select name="component"><option value="BONUS">Bonus or arrears (earning)</option><option value="ADVANCE">Salary advance recovery</option><option value="OTHER">Other deduction</option></select></label>
    <label class="field">Amount (₹)<input name="amount" required></label><label class="field">Note<input name="note"></label><div><button class="btn" type="submit">Add</button></div></form>
    @foreach ($inputs as $in)<p class="small">{{ $in->period }} · {{ $in->component }} · {{ $money($in->amount) }} {{ $in->note }}</p>@endforeach</details>
</section>
@endcan
<div class="cols">
<section class="card"><h2>Leave this year</h2><div class="table-wrap"><table><tbody>@foreach ($balances as $b)<tr><td>{{ $b['type']->name }}</td><td class="num">{{ $b['balance'] === null ? $b['used'].' used' : $b['balance'].' of '.$b['allotted'].' left' }}</td></tr>@endforeach</tbody></table></div></section>
<section class="card"><h2>Payslips</h2>@forelse ($payslips as $ps)<p><a href="{{ route('app.hr.payslips.show', $ps) }}">{{ $ps->run->label() }}</a> · net {{ $money($ps->net) }} · {{ $ps->run->status }}</p>@empty<p class="empty">None yet.</p>@endforelse</section>
</div>
<section class="card"><h2>Documents</h2><div class="table-wrap"><table><tbody>
@forelse ($documents as $d)<tr><td>{{ config('hr.documents')[$d->type] }}</td><td><a href="{{ route('app.hr.documents.show', $d) }}">{{ $d->title }}</a></td><td>{{ $d->expires_on ? 'expires '.$d->expires_on->format('j M Y') : '' }}</td>
<td>@can('academic.hr.manage')<form method="post" action="{{ route('app.hr.documents.destroy', $d) }}" class="inline">@csrf @method('delete')<button class="btn sm danger">Delete</button></form>@endcan</td></tr>
@empty<tr><td class="empty">No documents.</td></tr>@endforelse</tbody></table></div>
@can('academic.hr.manage')<form method="post" enctype="multipart/form-data" action="{{ route('app.hr.documents.store', $s) }}" class="form">@csrf
    <label class="field">Type<select name="type">@foreach (config('hr.documents') as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label><label class="field">Title<input name="title"></label>
    <label class="field">Expires on<input type="date" name="expires_on"></label><label class="field">File (PDF or image, up to 5 MB)<input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp"></label><div><button class="btn" type="submit">Upload</button></div></form>@endcan
</section>
@can('academic.hr.manage')
<details class="card"><summary>Edit employee details</summary><form method="post" action="{{ route('app.hr.employees.update', $s) }}">@csrf @method('put') @include('hr::employees.fields')<div class="toolbar" style="margin-top:14px"><button class="btn primary" type="submit">Save</button></div></form></details>
<details class="card danger-zone"><summary>Resignation and relieving</summary><form method="post" action="{{ route('app.hr.employees.relieve', $s) }}" class="form">@csrf
    <label class="field">Resigned on<input type="date" name="resigned_on" value="{{ $p->resigned_on?->format('Y-m-d') }}"></label><label class="field">Relieved on<input type="date" name="relieved_on" value="{{ $p->relieved_on?->format('Y-m-d') }}" required></label>
    <label class="field wide">Reason<input name="exit_reason" value="{{ $p->exit_reason }}"></label><div><button class="btn danger" type="submit">Save relieving</button></div></form>
    @if ($p->relieved_on)@can('academic.payroll.process')<form method="post" action="{{ route('app.hr.fnf.prepare', $s) }}" class="form" style="margin-top:12px">@csrf
        <label class="field">Advance to recover (₹)<input name="advance_recovery" value="0"></label><label class="field">Other deductions (₹)<input name="other_deductions" value="0"></label><label class="field">TDS (₹)<input name="tds" value="0"></label>
        <div><button class="btn primary" type="submit">{{ $fnf ? 'Recalculate' : 'Work out' }} full and final</button></div></form>@if ($fnf)<p><a href="{{ route('app.hr.fnf.show', $fnf) }}">Settlement: {{ $money($fnf->net) }} · {{ $fnf->status }}</a></p>@endif @endcan @endif
</details>
@endcan
@endsection
