<div class="form">
    <label class="field">Employee code<input name="employee_code" value="{{ old('employee_code', $s->employee_code) }}" {{ $s->exists ? 'readonly' : 'required' }} maxlength="20"></label>
    <label class="field">Name<input name="name" value="{{ old('name', $s->name) }}" {{ $s->exists ? 'readonly' : 'required' }} maxlength="150"></label>
    <label class="field">Email<input type="email" name="email" value="{{ old('email', $s->email) }}"></label>
    <label class="field">Phone<input name="phone" value="{{ old('phone', $s->phone) }}" maxlength="15"></label>
    <label class="field">Joined on<input type="date" name="joined_on" value="{{ old('joined_on', $s->joined_on?->format('Y-m-d')) }}"></label>
    <label class="field">Branch<select name="branch_id"><option value="">—</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $s->branch_id) === $b->id)>{{ $b->name }}</option>@endforeach</select></label>
    <label class="field">Department<select name="department_id"><option value="">—</option>@foreach ($departments as $d)<option value="{{ $d->id }}" @selected(old('department_id', $p->department_id) === $d->id)>{{ $d->name }}</option>@endforeach</select></label>
    <label class="field">Designation<select name="designation_id"><option value="">—</option>@foreach ($designations as $d)<option value="{{ $d->id }}" @selected(old('designation_id', $p->designation_id) === $d->id)>{{ $d->name }}</option>@endforeach</select></label>
    <label class="field">Shift<select name="shift_id">@foreach ($shifts as $x)<option value="{{ $x->id }}" @selected(old('shift_id', $p->shift_id) === $x->id)>{{ $x->name }} ({{ substr($x->starts_at, 0, 5) }}–{{ substr($x->ends_at, 0, 5) }})</option>@endforeach</select></label>
    <label class="field">Employment<select name="employment_type">@foreach (['permanent' => 'Permanent', 'probation' => 'Probation', 'contract' => 'Contract', 'part_time' => 'Part time'] as $k => $l)<option value="{{ $k }}" @selected(old('employment_type', $p->employment_type) === $k)>{{ $l }}</option>@endforeach</select></label>
    <input type="hidden" name="is_teaching" value="0"><label class="check"><input type="checkbox" name="is_teaching" value="1" @checked(old('is_teaching', $s->is_teaching))> Teaching staff</label>
    <label class="field">Date of birth<input type="date" name="date_of_birth" value="{{ old('date_of_birth', $p->date_of_birth?->format('Y-m-d')) }}"></label>
    <label class="field">PAN <span class="hint">{{ $p->pan ? $p->maskedPan().' saved' : '' }}</span><input name="pan" maxlength="10" autocomplete="off" placeholder="{{ $p->pan ? 'Leave blank to keep' : 'ABCDE1234F' }}"></label>
    <label class="field">UAN (PF)<input name="uan" value="{{ old('uan', $p->uan) }}" maxlength="12"></label>
    <label class="field">ESI number<input name="esi_number" value="{{ old('esi_number', $p->esi_number) }}" maxlength="17"></label>
    <label class="field">Bank account <span class="hint">{{ $p->bank_account ? $p->maskedAccount().' saved' : '' }}</span><input name="bank_account" autocomplete="off" placeholder="{{ $p->bank_account ? 'Leave blank to keep' : '' }}"></label>
    <label class="field">IFSC<input name="bank_ifsc" value="{{ old('bank_ifsc', $p->bank_ifsc) }}" maxlength="11"></label>
    <label class="field">Bank<input name="bank_name" value="{{ old('bank_name', $p->bank_name) }}"></label>
    <label class="field">Account holder<input name="account_holder" value="{{ old('account_holder', $p->account_holder) }}"></label>
    <input type="hidden" name="pf_applicable" value="0"><label class="check"><input type="checkbox" name="pf_applicable" value="1" @checked(old('pf_applicable', $p->pf_applicable))> PF applies</label>
    <input type="hidden" name="esi_applicable" value="0"><label class="check"><input type="checkbox" name="esi_applicable" value="1" @checked(old('esi_applicable', $p->esi_applicable))> ESI applies (when gross is within the limit)</label>
    <label class="field">Professional tax state<select name="pt_state"><option value="">Branch's state</option>@foreach ($states as $code => $name)<option value="{{ $code }}" @selected(old('pt_state', $p->pt_state) === $code)>{{ $name }}</option>@endforeach</select></label>
    <label class="field">Tax regime<select name="tax_regime"><option value="new" @selected($p->tax_regime !== 'old')>New regime</option><option value="old" @selected($p->tax_regime === 'old')>Old regime</option></select></label>
    <label class="field">80C declared (old regime, ₹ a year)<input name="declared_80c" value="{{ \Modules\Accounting\Support\Amount::input((int) $p->declared_80c) }}"></label>
    <label class="field">Notice period (days)<input type="number" name="notice_days" value="{{ old('notice_days', $p->notice_days) }}" min="0"></label>
</div>
