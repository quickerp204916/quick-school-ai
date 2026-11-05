@extends('academic::layout')
@section('title', 'Payroll settings')
@section('content')
<div class="head"><h1>HR and payroll settings</h1></div>
@include('hr::partials.tabs')
<section class="card"><h2>Statutory rules</h2><p class="small muted">Each rule has versions with effective dates. Payroll uses the version in force on the last day of the month; approved months keep the version they used. Confirm values with your payroll consultant.</p>
@foreach ($rules as $code => $versions)<details><summary>{{ strtoupper($code) }} · in force from {{ $versions->first()->effective_from->format('j M Y') }} ({{ $versions->count() }} version{{ $versions->count() > 1 ? 's' : '' }})</summary>
    @foreach ($versions as $v)<p class="small"><b>From {{ $v->effective_from->format('j M Y') }}</b> {{ $v->note }}</p><pre class="small" style="white-space:pre-wrap">{{ json_encode($v->config, JSON_PRETTY_PRINT) }}</pre>@endforeach</details>@endforeach
<form method="post" action="{{ route('app.hr.settings.rules') }}" class="form" style="margin-top:12px">@csrf<label class="field">Rule<select name="code">@foreach (array_keys(config('hr.statutory')) as $c)<option value="{{ $c }}">{{ strtoupper($c) }}</option>@endforeach</select></label>
    <label class="field">Effective from<input type="date" name="effective_from" required></label><label class="field wide">Changes (JSON; only the values that change)<textarea name="config" placeholder='{"employee_bp": 1000}' required></textarea></label>
    <label class="field wide">Note<input name="note" placeholder="Notification no. and date"></label><div><button class="btn primary" type="submit">Add version</button></div></form></section>
<div class="cols">
<section class="card"><h2>Shifts</h2>@foreach ($shifts as $s)<form method="post" action="{{ route('app.hr.settings.shift', $s) }}" class="form">@csrf @method('put')
    <label class="field">Name<input name="name" value="{{ $s->name }}"></label><label class="field">Starts<input type="time" name="starts_at" value="{{ substr($s->starts_at, 0, 5) }}"></label><label class="field">Ends<input type="time" name="ends_at" value="{{ substr($s->ends_at, 0, 5) }}"></label>
    <label class="field">Break (min)<input type="number" name="break_minutes" value="{{ $s->break_minutes }}"></label><label class="field">Grace (min)<input type="number" name="grace_minutes" value="{{ $s->grace_minutes }}"></label>
    <fieldset class="field wide"><legend>Weekly off</legend>@foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $i => $d)<label class="check" style="display:inline-flex;margin-right:8px"><input type="checkbox" name="weekly_off[]" value="{{ $i }}" @checked(in_array($i, $s->weekly_off))> {{ $d }}</label>@endforeach</fieldset>
    <div><button class="btn sm" type="submit">Save shift</button></div></form>@endforeach</section>
<section class="card"><h2>Leave types</h2><div class="table-wrap"><table><tbody>@foreach ($types as $t)<tr><td>{{ $t->name }}</td><td class="num">{{ $t->days_per_year }} a year</td><td>{{ $t->is_paid ? 'paid' : 'unpaid' }}{{ $t->carry_forward_max ? ', carry up to '.$t->carry_forward_max : '' }}{{ $t->encashable ? ', encashable' : '' }}</td></tr>@endforeach</tbody></table></div>
<h2 style="margin-top:14px">Salary components</h2><div class="table-wrap"><table><tbody>@foreach ($components as $c)<tr><td>{{ $c->code }}</td><td>{{ $c->name }}</td><td>{{ $c->kind }}{{ $c->is_computed ? ', computed' : '' }}</td><td>{{ $c->ledger->name }}</td></tr>@endforeach</tbody></table></div></section>
</div>
@endsection
