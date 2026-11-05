{{-- Student profile fields. Needs $s (Student) and $states. --}}
<div class="form">
    <label class="field">First name<input type="text" name="first_name" value="{{ old('first_name', $s->first_name) }}" required maxlength="80"></label>
    <label class="field">Last name<input type="text" name="last_name" value="{{ old('last_name', $s->last_name) }}" maxlength="80"></label>
    <label class="field">Gender<select name="gender" required>@foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $k => $l)<option value="{{ $k }}" @selected(old('gender', $s->gender) === $k)>{{ $l }}</option>@endforeach</select></label>
    <label class="field">Date of birth<input type="date" name="date_of_birth" value="{{ old('date_of_birth', $s->date_of_birth?->format('Y-m-d')) }}" required max="{{ today()->format('Y-m-d') }}"></label>
    <label class="field">Blood group<select name="blood_group"><option value="">—</option>@foreach (config('academic.blood_groups') as $b)<option @selected(old('blood_group', $s->blood_group) === $b)>{{ $b }}</option>@endforeach</select></label>
    <label class="field">Category<select name="category"><option value="">—</option>@foreach (config('academic.categories') as $k => $l)<option value="{{ $k }}" @selected(old('category', $s->category) === $k)>{{ $l }}</option>@endforeach</select></label>
    <label class="field">Nationality<input type="text" name="nationality" value="{{ old('nationality', $s->nationality ?? 'Indian') }}"></label>
    <label class="field">Mother tongue<input type="text" name="mother_tongue" value="{{ old('mother_tongue', $s->mother_tongue) }}"></label>
    <label class="field">PEN <span class="hint">UDISE+ Permanent Education Number</span><input type="text" name="pen" value="{{ old('pen', $s->pen) }}" maxlength="20"></label>
    <label class="field">APAAR ID<input type="text" name="apaar_id" value="{{ old('apaar_id', $s->apaar_id) }}" maxlength="20"></label>
    <label class="field">Student mobile<input type="text" name="phone" value="{{ old('phone', $s->phone) }}" inputmode="tel"></label>
    <label class="field">Student email <span class="hint">needed for the student app</span><input type="email" name="email" value="{{ old('email', $s->email) }}"></label>
    <label class="field wide">Address<input type="text" name="address" value="{{ old('address', $s->address) }}"></label>
    <label class="field">City<input type="text" name="city" value="{{ old('city', $s->city) }}"></label>
    <label class="field">State<select name="state_code"><option value="">—</option>@foreach ($states as $c => $n)<option value="{{ $c }}" @selected(old('state_code', $s->state_code) === $c)>{{ $n }}</option>@endforeach</select></label>
    <label class="field">PIN code<input type="text" name="pincode" value="{{ old('pincode', $s->pincode) }}" inputmode="numeric" maxlength="6"></label>
    <label class="field">Previous school<input type="text" name="previous_school" value="{{ old('previous_school', $s->previous_school) }}"></label>
</div>
