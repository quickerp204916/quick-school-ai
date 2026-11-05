@extends('academic::layout')
@section('title', 'Institution setup')
@section('content')
<div class="head"><h1>Institution setup</h1></div>
@include('academic::setup.nav')
<form class="card" method="post" action="{{ route('app.setup.profile') }}">@csrf @method('put')
    <h2>Institution profile</h2>
    <div class="form">
        <label class="field">Academic structure <span class="hint">Changes the words used everywhere</span>
            <select name="structure">@foreach (config('academic.structures') as $k => $s)<option value="{{ $k }}" @selected(old('structure', $profile->structure) === $k)>{{ $s['label'] }} ({{ $s['terms']['level'] }} · {{ $s['terms']['section'] }})</option>@endforeach</select></label>
        <label class="field">Board or university<input type="text" name="board" value="{{ old('board', $profile->board) }}" maxlength="40" placeholder="CBSE"></label>
        <label class="field">Affiliation number<input type="text" name="affiliation_no" value="{{ old('affiliation_no', $profile->affiliation_no) }}" maxlength="40"></label>
        <label class="field">UDISE+ code<input type="text" name="udise_code" value="{{ old('udise_code', $profile->udise_code) }}" maxlength="11" inputmode="numeric"></label>
        <label class="field">Principal or head<input type="text" name="principal_name" value="{{ old('principal_name', $profile->principal_name) }}"></label>
        <label class="field">Phone<input type="text" name="phone" value="{{ old('phone', $profile->phone) }}" inputmode="tel"></label>
        <label class="field">Email<input type="email" name="email" value="{{ old('email', $profile->email) }}"></label>
        <label class="field">Website<input type="text" name="website" value="{{ old('website', $profile->website) }}"></label>
        <label class="field wide">Address (printed on certificates and report cards)<input type="text" name="address" value="{{ old('address', $profile->address) }}"></label>
        <label class="field">Attendance taken<select name="attendance_mode"><option value="daily" @selected(old('attendance_mode', $profile->attendance_mode) === 'daily')>Once a day</option><option value="period" @selected(old('attendance_mode', $profile->attendance_mode) === 'period')>Every period</option></select></label>
        <label class="field">Teachers may edit attendance for <span class="hint">days after the date</span><input type="number" name="attendance_edit_days" min="0" max="30" value="{{ old('attendance_edit_days', $profile->attendance_edit_days) }}"></label>
        <label class="field">Low attendance below (%)<input type="number" name="low_attendance_percent" min="1" max="100" value="{{ old('low_attendance_percent', $profile->low_attendance_percent) }}"></label>
        <label class="field">Admission number prefix<input type="text" name="admission_prefix" value="{{ old('admission_prefix', $profile->admission_prefix) }}" maxlength="10"></label>
        <fieldset class="wide" style="border:0;padding:0;margin:0"><legend class="small" style="font-weight:500">Working days</legend><div class="toolbar">
            @foreach (\Modules\Academic\Models\TimetableEntry::DAYS as $n => $d)
                <label class="check"><input type="checkbox" name="working_days[]" value="{{ $n }}" @checked(in_array($n, array_map('intval', old('working_days', $profile->working_days ?? [])), true))> {{ $d }}</label>
            @endforeach</div></fieldset>
        <fieldset class="wide" style="border:0;padding:0;margin:0"><legend class="small" style="font-weight:500">Documents needed before admission</legend><div class="toolbar">
            @foreach (config('academic.admission.documents') as $k => $l)
                <label class="check"><input type="checkbox" name="required_documents[]" value="{{ $k }}" @checked(in_array($k, old('required_documents', $profile->required_documents ?? []), true))> {{ $l }}</label>
            @endforeach</div></fieldset>
    </div>
    <p><button class="btn primary" type="submit">Save profile</button></p>
</form>

<section class="card"><h2>Branches</h2>
    <p class="small muted" style="margin-top:-6px">Your plan allows {{ $branchLimit === null ? 'unlimited' : $branchLimit }} {{ \Illuminate\Support\Str::plural('branch', $branchLimit ?? 2) }}.</p>
    <div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>City</th><th>State</th><th>Phone</th><th>Status</th><th></th></tr></thead><tbody>
    @foreach ($branches as $b)
        <tr><td>{{ $b->code }}</td><td><b>{{ $b->name }}</b> @if ($b->is_primary)<span class="chip">main</span>@endif</td><td>{{ $b->city }}</td><td>{{ $b->state_code ? ($states[$b->state_code] ?? $b->state_code) : '' }}</td>
            <td>{{ $b->phone }}</td><td><span class="chip {{ $b->is_active ? 'active' : 'closed' }}">{{ $b->is_active ? 'active' : 'inactive' }}</span></td>
            <td><details><summary class="small">Edit</summary>
                <form method="post" action="{{ route('app.setup.branches.update', $b) }}" class="form" style="margin-top:8px;min-width:280px">@csrf @method('put')
                    <input type="hidden" name="code" value="{{ $b->code }}">
                    <label class="field">Name<input type="text" name="name" value="{{ $b->name }}" required></label>
                    <label class="field">City<input type="text" name="city" value="{{ $b->city }}"></label>
                    <label class="field">State<select name="state_code"><option value="">—</option>@foreach ($states as $c => $n)<option value="{{ $c }}" @selected($b->state_code === $c)>{{ $n }}</option>@endforeach</select></label>
                    <label class="field">Phone<input type="text" name="phone" value="{{ $b->phone }}"></label>
                    <label class="field">Email<input type="email" name="email" value="{{ $b->email }}"></label>
                    <label class="field wide">Address<input type="text" name="address" value="{{ $b->address }}"></label>
                    <input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" @checked($b->is_active)> Active</label>
                    <button class="btn sm" type="submit">Save</button>
                </form></details></td></tr>
    @endforeach</tbody></table></div>
    <details style="margin-top:12px"><summary class="btn sm">Add a branch</summary>
        <form method="post" action="{{ route('app.setup.branches.store') }}" class="form" style="margin-top:12px">@csrf
            <label class="field">Code <span class="hint">Short, e.g. EAST</span><input type="text" name="code" value="{{ old('code') }}" required maxlength="20"></label>
            <label class="field">Name<input type="text" name="name" value="{{ old('name') }}" required></label>
            <label class="field">City<input type="text" name="city" value="{{ old('city') }}"></label>
            <label class="field">State<select name="state_code"><option value="">—</option>@foreach ($states as $c => $n)<option value="{{ $c }}" @selected(old('state_code') === $c)>{{ $n }}</option>@endforeach</select></label>
            <label class="field">Phone<input type="text" name="phone" value="{{ old('phone') }}"></label>
            <label class="field wide">Address<input type="text" name="address" value="{{ old('address') }}"></label>
            <input type="hidden" name="is_active" value="1">
            <div class="wide"><button class="btn primary" type="submit">Add branch</button></div>
        </form></details>
</section>
@endsection
