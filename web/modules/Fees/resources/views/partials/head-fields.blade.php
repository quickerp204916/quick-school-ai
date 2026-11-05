<label class="field">Code<input name="code" value="{{ $h?->code }}" required maxlength="20"></label>
<label class="field">Name<input name="name" value="{{ $h?->name }}" required maxlength="120"></label>
<label class="field">Kind<select name="kind">@foreach (\Modules\Fees\Models\FeeHead::KINDS as $k => $l)<option value="{{ $k }}" @selected($h?->kind === $k)>{{ $l }}</option>@endforeach</select></label>
<label class="field">Posts to ledger<select name="ledger_id" required>@foreach ($ledgers as $l)<option value="{{ $l->id }}" @selected($h?->ledger_id === $l->id)>{{ $l->name }} ({{ $l->code }})</option>@endforeach</select></label>
<input type="hidden" name="is_refundable" value="0"><label class="check"><input type="checkbox" name="is_refundable" value="1" @checked($h?->is_refundable)> Refundable</label>
<input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" @checked($h ? $h->is_active : true)> Active</label>
