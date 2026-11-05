@extends('platform::layout')
@section('title', 'Onboard institution')
@section('content')
<div class="head"><div><h1>Onboard an institution</h1><p class="muted" style="margin:4px 0 0">Creates the institution, its main branch and current academic session, the owner's account and the subscription. The owner gets an email to set their password.</p></div></div>

<form class="card" method="post" action="{{ route('platform.tenants.store') }}">
    @csrf
    <div class="form">
        <h2 class="wide">Institution</h2>
        <label class="field">Name<input type="text" name="name" value="{{ old('name') }}" required maxlength="200" placeholder="Sunrise Public School"></label>
        <label class="field">Code<span class="hint">Becomes the web address: code.{{ config('platform.base_domain') }}</span>
            <input type="text" name="code" value="{{ old('code') }}" required pattern="[a-z0-9][a-z0-9-]{1,38}[a-z0-9]" placeholder="sunrise-noida"></label>
        <label class="field">Type<select name="type" required>@foreach (config('platform.institution_types') as $k => $label)<option value="{{ $k }}" @selected(old('type', 'school') === $k)>{{ $label }}</option>@endforeach</select></label>
        <label class="field">Legal name <span class="hint">As on GST registration, if different</span><input type="text" name="legal_name" value="{{ old('legal_name') }}" maxlength="200"></label>
        <label class="field">City<input type="text" name="city" value="{{ old('city') }}" maxlength="100"></label>
        <label class="field">Main branch name<input type="text" name="branch_name" value="{{ old('branch_name') }}" maxlength="150" placeholder="Main campus"></label>
        <label class="field wide">Address<input type="text" name="address" value="{{ old('address') }}" maxlength="500"></label>

        <h2 class="wide" style="margin-top:8px">Owner</h2>
        <label class="field">Owner name<input type="text" name="owner_name" value="{{ old('owner_name') }}" required maxlength="150"></label>
        <label class="field">Owner email<input type="email" name="owner_email" value="{{ old('owner_email') }}" required maxlength="191"></label>
        <label class="field">Owner mobile<span class="hint">10 digits, optional</span><input type="text" name="owner_phone" value="{{ old('owner_phone') }}" inputmode="numeric" pattern="[6-9][0-9]{9}"></label>

        <h2 class="wide" style="margin-top:8px">Plan and billing</h2>
        <label class="field">Plan<select name="plan_id" required>@foreach ($plans as $p)
            <option value="{{ $p->id }}" @selected(old('plan_id') === $p->id)>{{ $p->name }} · {{ \App\Core\Support\Money::of($p->price_monthly)->format() }}/mo · {{ $p->trial_days }}-day trial{{ $p->is_public ? '' : ' · private' }}</option>@endforeach</select></label>
        <label class="field">Billing cycle<select name="billing_cycle"><option value="monthly" @selected(old('billing_cycle') !== 'yearly')>Monthly</option><option value="yearly" @selected(old('billing_cycle') === 'yearly')>Yearly</option></select></label>
        <label class="field">GSTIN <span class="hint">Optional, 15 characters</span><input type="text" name="billing_gstin" value="{{ old('billing_gstin') }}" maxlength="15" style="text-transform:uppercase"></label>
        <label class="field">State for GST<select name="billing_state_code"><option value="">Not known</option>@foreach ($states as $code => $name)<option value="{{ $code }}" @selected(old('billing_state_code') === $code)>{{ $code }} · {{ $name }}</option>@endforeach</select></label>
    </div>
    <div style="margin-top:18px;display:flex;gap:10px"><button class="btn primary" type="submit">Create institution</button><a class="btn" href="{{ route('platform.tenants.index') }}">Cancel</a></div>
</form>
@endsection
