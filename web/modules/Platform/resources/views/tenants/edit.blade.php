@extends('platform::layout')
@section('title', 'Edit '.$tenant->name)
@section('content')
<div class="head"><h1>Edit {{ $tenant->name }}</h1><a class="btn" href="{{ route('platform.tenants.show', $tenant) }}">Back</a></div>
<form class="card" method="post" action="{{ route('platform.tenants.update', $tenant) }}">
    @csrf @method('put')
    <div class="form">
        <label class="field">Name<input type="text" name="name" value="{{ old('name', $tenant->name) }}" required maxlength="200"></label>
        <label class="field">Legal name<input type="text" name="legal_name" value="{{ old('legal_name', $tenant->legal_name) }}" maxlength="200"></label>
        <label class="field">Type<select name="type">@foreach (config('platform.institution_types') as $k => $label)<option value="{{ $k }}" @selected(old('type', $tenant->type) === $k)>{{ $label }}</option>@endforeach</select></label>
        <label class="field">Contact name<input type="text" name="contact_name" value="{{ old('contact_name', $tenant->contact_name) }}" required maxlength="150"></label>
        <label class="field">Contact email<input type="email" name="contact_email" value="{{ old('contact_email', $tenant->contact_email) }}" required maxlength="191"></label>
        <label class="field">Contact mobile<input type="text" name="contact_phone" value="{{ old('contact_phone', $tenant->contact_phone) }}" pattern="[6-9][0-9]{9}"></label>
        <label class="field">GSTIN<input type="text" name="billing_gstin" value="{{ old('billing_gstin', $tenant->billing_gstin) }}" maxlength="15" style="text-transform:uppercase"></label>
        <label class="field">State for GST<select name="billing_state_code"><option value="">Not known</option>@foreach ($states as $code => $name)<option value="{{ $code }}" @selected(old('billing_state_code', $tenant->billing_state_code) === $code)>{{ $code }} · {{ $name }}</option>@endforeach</select></label>
        <label class="field">City<input type="text" name="city" value="{{ old('city', $tenant->city) }}" maxlength="100"></label>
        <label class="field wide">Address<input type="text" name="address" value="{{ old('address', $tenant->address) }}" maxlength="500"></label>
    </div>
    <p class="small muted">The institution code and web address cannot be changed after onboarding. GST details apply to invoices issued from now on.</p>
    <button class="btn primary" type="submit">Save</button>
</form>
@endsection
