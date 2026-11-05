<div class="form">
    <label class="field">Code<input type="text" name="code" value="{{ old('code', $l->code) }}" required maxlength="30" placeholder="V-SHARMA-ELEC"></label>
    <label class="field">Name<input type="text" name="name" value="{{ old('name', $l->name) }}" required maxlength="150"></label>
    <label class="field">Group<select name="group_id" required>@foreach (\Modules\Accounting\Models\AccountGroup::orderBy('sort_order')->get() as $g)<option value="{{ $g->id }}" @selected(old('group_id', $l->group_id) === $g->id)>{{ $g->code }} · {{ $g->name }}</option>@endforeach</select></label>
    <label class="field">Type<select name="type">@foreach (config('accounting.ledger_types') as $k => $t)<option value="{{ $k }}" @selected(old('type', $l->type) === $k)>{{ $t }}</option>@endforeach</select></label>
    <label class="field">GSTIN<input type="text" name="gstin" value="{{ old('gstin', $l->gstin) }}" maxlength="15" style="text-transform:uppercase"></label>
    <label class="field">State code <span class="hint">place of supply</span><input type="text" name="state_code" value="{{ old('state_code', $l->state_code) }}" maxlength="2" inputmode="numeric"></label>
    <label class="field">PAN<input type="text" name="pan" value="{{ old('pan', $l->pan) }}" maxlength="10" style="text-transform:uppercase"></label>
    <label class="field">TDS section <span class="hint">vendors</span><select name="tds_section_id"><option value="">None</option>@foreach (\Modules\Accounting\Models\TdsSection::whereNull('effective_to')->orderBy('code')->get() as $s)<option value="{{ $s->id }}" @selected(old('tds_section_id', $l->tds_section_id) === $s->id)>{{ $s->code }} · {{ $s->name }} · {{ $s->rate_bp / 100 }}%</option>@endforeach</select></label>
    <label class="field">Bank name<input type="text" name="bank_name" value="{{ old('bank_name', $l->bank_name) }}"></label>
    <label class="field">Account number<input type="text" name="account_number" value="{{ old('account_number', $l->account_number) }}"></label>
    <label class="field">IFSC<input type="text" name="ifsc" value="{{ old('ifsc', $l->ifsc) }}" maxlength="11" style="text-transform:uppercase"></label>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $l->is_active))> Active</label>
</div>
