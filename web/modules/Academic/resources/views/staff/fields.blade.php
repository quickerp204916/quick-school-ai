<div class="form">
    <label class="field">Employee code<input type="text" name="employee_code" value="{{ old('employee_code', $s->employee_code) }}" required maxlength="20"></label>
    <label class="field">Full name<input type="text" name="name" value="{{ old('name', $s->name) }}" required></label>
    <label class="field">Designation<input type="text" name="designation" value="{{ old('designation', $s->designation) }}" placeholder="PGT Mathematics"></label>
    <label class="field">Department<input type="text" name="department" value="{{ old('department', $s->department) }}"></label>
    <label class="field">Branch<select name="branch_id"><option value="">—</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $s->branch_id) === $b->id)>{{ $b->name }}</option>@endforeach</select></label>
    <label class="field">Joined on<input type="date" name="joined_on" value="{{ old('joined_on', $s->joined_on?->format('Y-m-d')) }}"></label>
    <label class="field">Email<input type="email" name="email" value="{{ old('email', $s->email) }}"></label>
    <label class="field">Mobile<input type="text" name="phone" value="{{ old('phone', $s->phone) }}" inputmode="tel"></label>
    <label class="field">Status<select name="status"><option value="active" @selected(old('status', $s->status) === 'active')>Active</option><option value="inactive" @selected(old('status', $s->status) === 'inactive')>Inactive (removes sign-in)</option></select></label>
    <input type="hidden" name="is_teaching" value="0"><label class="check"><input type="checkbox" name="is_teaching" value="1" @checked(old('is_teaching', $s->is_teaching))> Teaching staff</label>
    <input type="hidden" name="create_login" value="0"><label class="check"><input type="checkbox" name="create_login" value="1" @checked(old('create_login', (bool) $s->user_id))> Can sign in (emails an invitation)</label>
    <label class="field">Role when signed in<select name="role">@foreach (['teacher', 'front_office', 'accountant', 'admin'] as $k)<option value="{{ $k }}" @selected(old('role', $role ?? ($s->is_teaching ? 'teacher' : 'front_office')) === $k)>{{ config('academic.roles.'.$k) }}</option>@endforeach</select></label>
</div>
