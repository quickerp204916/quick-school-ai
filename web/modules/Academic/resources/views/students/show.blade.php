@extends('academic::layout')
@section('title', $s->fullName())
@section('content')
@php($manage = auth()->user()->can('academic.students.manage'))
<div class="head"><div class="toolbar" style="align-items:center;gap:14px">
    @if ($s->photo_path)<img src="{{ route('app.students.photo', $s) }}" alt="Photo of {{ $s->fullName() }}" style="width:64px;height:80px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
    @else<div style="width:64px;height:80px;border-radius:8px;background:var(--neutral-bg);display:grid;place-items:center;font-weight:700;color:var(--muted)" aria-hidden="true">{{ mb_substr($s->first_name, 0, 1) }}{{ mb_substr((string) $s->last_name, 0, 1) }}</div>@endif
    <div><h1>{{ $s->fullName() }} <span class="chip {{ $s->status }}">{{ $s->status }}</span></h1>
    <p class="muted" style="margin:4px 0 0">Admission no {{ $s->admission_no }} · {{ $current ? $current->section->label().' · Roll '.($current->roll_no ?? '—') : 'Not enrolled' }} · {{ $s->branch?->name }}</p></div></div>
    <div class="toolbar">@if ($manage)<a class="btn" href="{{ route('app.students.edit', $s) }}">Edit</a>
        @if ($s->email && ! $s->user_id)<form class="inline" method="post" action="{{ route('app.students.invite', $s) }}">@csrf<button class="btn" type="submit">Invite to student app</button></form>@endif @endif
        @can('academic.certificates.issue')<a class="btn" href="{{ route('app.certificates.create', ['student' => $s->id]) }}">Issue certificate</a>@endcan</div></div>
<div class="grid">
    <div class="card kpi"><div class="label">Attendance this session</div><div class="value">{{ $attendance && $attendance['percent'] !== null ? $attendance['percent'].'%' : '—' }}</div>
        <div class="small muted">@if ($attendance){{ $attendance['counts']['absent'] }} absent of {{ $attendance['total'] }} marked @endif</div></div>
    <div class="card kpi"><div class="label">Latest result</div>@php($rc = $reportCards->first())
        <div class="value">{{ $rc ? $rc->percentage.'%' : '—' }}</div><div class="small muted">{{ $rc ? $rc->title.' · '.$rc->result.($rc->rank ? ' · rank '.$rc->rank : '') : 'No report card yet' }}</div></div>
    <div class="card kpi"><div class="label">Admitted</div><div class="value" style="font-size:20px">{{ $s->admitted_on->format('j M Y') }}</div><div class="small muted">{{ $s->previous_school ? 'From '.$s->previous_school : '' }}</div></div>
</div>
<div class="cols">
<section class="card"><h2>Profile</h2><dl class="kv">
    <dt>Gender</dt><dd>{{ ucfirst($s->gender) }}</dd><dt>Date of birth</dt><dd>{{ $s->date_of_birth->format('j M Y') }} ({{ $s->date_of_birth->age }} years)</dd>
    <dt>Blood group</dt><dd>{{ $s->blood_group ?: '—' }}</dd><dt>Category</dt><dd>{{ $s->category ? config('academic.categories.'.$s->category) : '—' }}</dd>
    <dt>PEN / APAAR</dt><dd>{{ $s->pen ?: '—' }} / {{ $s->apaar_id ?: '—' }}</dd><dt>Contact</dt><dd>{{ $s->phone ?: '—' }} {{ $s->email ? '· '.$s->email : '' }}</dd>
    <dt>Address</dt><dd>{{ collect([$s->address, $s->city, $s->pincode])->filter()->implode(', ') ?: '—' }}</dd>
    @if ($s->left_on)<dt>Left on</dt><dd>{{ $s->left_on->format('j M Y') }} · {{ $s->leaving_reason }}</dd>@endif
    <dt>App access</dt><dd>{{ $s->user_id ? 'Invited' : 'Not invited' }}</dd></dl></section>
<section class="card"><h2>Guardians</h2>
    @foreach ($s->guardians as $g)
    <div style="border-bottom:1px solid var(--border);padding-bottom:10px;margin-bottom:10px">
        <b><a href="{{ route('app.guardians.show', $g) }}">{{ $g->name }}</a></b> · {{ config('academic.relations.'.$g->pivot->relation) }}
        @if ($g->pivot->is_primary)<span class="chip current">primary</span>@endif
        <div class="small muted">{{ $g->phone }} {{ $g->email ? '· '.$g->email : '' }} {{ $g->occupation ? '· '.$g->occupation : '' }} {{ $g->pivot->can_pickup ? '' : '· not allowed to pick up' }}</div>
        @if ($manage)<div class="toolbar" style="margin-top:6px">
            @unless ($g->pivot->is_primary)<form class="inline" method="post" action="{{ route('app.students.guardians.primary', [$s, $g]) }}">@csrf<button class="btn sm" type="submit">Make primary</button></form>@endunless
            @if ($g->email && ! $g->user_id)<form class="inline" method="post" action="{{ route('app.guardians.invite', $g) }}">@csrf<button class="btn sm" type="submit">Invite to parent app</button></form>@endif
            @if ($s->guardians->count() > 1)<form class="inline" method="post" action="{{ route('app.students.guardians.destroy', [$s, $g]) }}">@csrf @method('delete')<button class="btn sm danger" type="submit">Remove</button></form>@endif
        </div>@endif
    </div>
    @endforeach
    @if ($manage)<details><summary class="btn sm">Add guardian</summary>
        <form method="post" action="{{ route('app.students.guardians.store', $s) }}" style="margin-top:10px">@csrf
            @include('academic::students.guardian-fields', ['name' => 'guardian', 'g' => [], 'required' => true, 'default' => 'mother'])
            <p><button class="btn" type="submit">Add</button></p></form></details>@endif
</section>
</div>
<section class="card"><h2>Documents and photo</h2>
    @include('academic::partials.documents', ['files' => $documents->keyBy('type'), 'uploadUrl' => route('app.students.uploads.store', $s), 'canChange' => $manage,
        'required' => [], 'received' => [], 'open' => true])
</section>
<section class="card"><h2>Enrolment history</h2><div class="table-wrap"><table><thead><tr><th>Session</th><th>{{ $t('section') }}</th><th>Roll</th><th>From</th><th>To</th><th>Status</th></tr></thead><tbody>
    @foreach ($s->enrollments as $e)
        <tr><td>{{ $e->session->name }}</td><td>{{ $e->section->label() }}</td>
            <td>@if ($manage && $e->status === 'active')<form method="post" action="{{ route('app.enrollments.roll', $e) }}" class="toolbar">@csrf @method('put')<input type="number" name="roll_no" value="{{ $e->roll_no }}" min="1" max="9999" style="width:80px" aria-label="Roll number"><button class="btn sm" type="submit">Save</button></form>@else{{ $e->roll_no }}@endif</td>
            <td>{{ $e->joined_on->format('j M Y') }}</td><td>{{ $e->left_on?->format('j M Y') }}</td><td><span class="chip {{ $e->status }}">{{ str_replace('_', ' ', $e->status) }}</span></td></tr>
    @endforeach</tbody></table></div>
    @if ($transfers->isNotEmpty())<h2 style="margin-top:14px">Transfers</h2>
        @foreach ($transfers as $tr)<p class="small" style="margin:0 0 4px">{{ $tr->effective_on->format('j M Y') }} · {{ ['section' => 'Section change', 'branch' => 'Branch transfer', 'out' => 'Left'][$tr->type] }}:
            {{ $tr->fromSection?->label() }} {{ $tr->toSection ? '→ '.$tr->toSection->label() : '' }} · {{ $tr->reason }}</p>@endforeach
    @endif
</section>
<div class="cols">
<section class="card"><h2>Report cards</h2>
    @forelse ($reportCards as $c)<p style="margin:0 0 6px"><a href="{{ route('app.report-cards.show', $c) }}">{{ $c->title }}</a> · {{ $c->percentage }}% · <span class="chip {{ $c->result }}">{{ $c->result }}</span></p>
    @empty<p class="muted">None published.</p>@endforelse</section>
<section class="card"><h2>Certificates</h2>
    @forelse ($certificates as $c)<p style="margin:0 0 6px">@can('academic.certificates.issue')<a href="{{ route('app.certificates.show', $c) }}">{{ $c->number }}</a>@else {{ $c->number }} @endcan
        · {{ config('academic.certificate_types.'.$c->type.'.label') }} · {{ $c->issued_on->format('j M Y') }} <span class="chip {{ $c->status }}">{{ $c->status }}</span></p>
    @empty<p class="muted">None issued.</p>@endforelse</section>
</div>
@if ($manage && $s->status === 'active' && $current)
<div class="cols">
<form class="card" method="post" action="{{ route('app.students.move', $s) }}">@csrf
    <h2>Change {{ strtolower($t('section')) }} or branch</h2>
    <div class="form">
        <label class="field">Move to<select name="section_id" required>@foreach ($moveTargets as $m)<option value="{{ $m->id }}">{{ $m->label() }} · {{ $m->branch->name }}</option>@endforeach</select></label>
        <label class="field">From<input type="date" name="effective_on" value="{{ today()->format('Y-m-d') }}" max="{{ today()->format('Y-m-d') }}" required></label>
        <label class="field">New roll number<input type="number" name="roll_no" min="1" max="9999"></label>
        <label class="field wide">Reason<input type="text" name="reason" required maxlength="200"></label>
    </div><p><button class="btn" type="submit">Move student</button></p>
</form>
<form class="card danger-zone" method="post" action="{{ route('app.students.leave', $s) }}">@csrf
    <h2>Student is leaving</h2>
    <div class="form">
        <label class="field">Type<select name="status"><option value="transferred">Transferred to another school</option><option value="withdrawn">Withdrawn</option></select></label>
        <label class="field">Last day<input type="date" name="left_on" value="{{ today()->format('Y-m-d') }}" max="{{ today()->format('Y-m-d') }}" required></label>
        <label class="field">Conduct<input type="text" name="conduct" value="{{ $s->conduct ?? 'Good' }}" maxlength="40"></label>
        <label class="field wide">Reason<input type="text" name="reason" required maxlength="200" placeholder="Family relocating to Pune"></label>
    </div><p><button class="btn danger" type="submit">Record leaving</button></p>
</form>
</div>
@endif
@endsection
