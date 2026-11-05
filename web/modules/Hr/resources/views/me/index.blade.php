@extends('academic::layout')
@section('title', 'My HR')
@section('content')
<div class="head"><div><h1>{{ $me->name }}</h1><p class="muted" style="margin:4px 0 0">{{ $me->employee_code }} · {{ $me->profile?->designation?->name ?? $me->designation }} · joined {{ $me->joined_on?->format('j M Y') }}</p></div></div>
<div class="cols">
<section class="card"><h2>My leave</h2><div class="table-wrap"><table><tbody>@foreach ($balances as $b)<tr><td>{{ $b['type']->name }}</td><td class="num">{{ $b['balance'] === null ? $b['used'].' taken' : $b['balance'].' left' }}</td></tr>@endforeach</tbody></table></div>
<form method="post" action="{{ route('app.me.leave') }}" class="form">@csrf<label class="field">Type<select name="leave_type_id">@foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></label>
    <label class="field">From<input type="date" name="from_date" required></label><label class="field">To<input type="date" name="to_date"></label><label class="check"><input type="checkbox" name="half_day" value="1"> Half day</label>
    <label class="field wide">Reason<input name="reason" required></label><div><button class="btn primary" type="submit">Apply</button></div></form>
@foreach ($leave as $l)<p class="small">{{ $l->type->name }} · {{ $l->from_date->format('j M') }}{{ $l->to_date != $l->from_date ? '–'.$l->to_date->format('j M') : '' }} · {{ $l->days }} day(s) · <b>{{ $l->status }}</b>
    @if ($l->status === 'pending')<form method="post" action="{{ route('app.me.leave.cancel', $l) }}" class="inline">@csrf<button class="btn sm">Cancel</button></form>@endif</p>@endforeach</section>
<section class="card"><h2>My payslips</h2>@forelse ($payslips as $p)<p><a href="{{ route('app.me.payslip', $p) }}">{{ $p->run->label() }}</a> · net {{ $money($p->net) }}</p>@empty<p class="empty">No payslips yet.</p>@endforelse
<h2 style="margin-top:14px">My documents</h2>@forelse ($documents as $d)<p><a href="{{ route('app.me.document', $d) }}">{{ $d->title }}</a></p>@empty<p class="empty">None.</p>@endforelse</section>
</div>
<section class="card"><h2>My attendance, last 30 days</h2><p class="small muted">Days not listed count as present.</p><div class="table-wrap"><table><tbody>@forelse ($attendance as $a)<tr><td>{{ $a->date->format('D j M') }}</td><td>{{ \Modules\Hr\Models\Attendance::STATUSES[$a->status] }}</td><td>{{ $a->remarks }}</td></tr>@empty<tr><td class="empty">Present every working day.</td></tr>@endforelse</tbody></table></div></section>
@endsection
