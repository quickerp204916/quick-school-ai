{{-- One guardian. $name: input name prefix, $g: existing values. --}}
<div class="form">
    <label class="field">Name<input type="text" name="{{ $name }}[name]" value="{{ $g['name'] ?? '' }}" @if ($required ?? false) required @endif maxlength="150"></label>
    <label class="field">Relation<select name="{{ $name }}[relation]">@foreach (config('academic.relations') as $k => $l)<option value="{{ $k }}" @selected(($g['relation'] ?? $default ?? 'father') === $k)>{{ $l }}</option>@endforeach</select></label>
    <label class="field">Mobile<input type="text" name="{{ $name }}[phone]" value="{{ $g['phone'] ?? '' }}" inputmode="tel" @if ($required ?? false) required @endif></label>
    <label class="field">Other phone<input type="text" name="{{ $name }}[alt_phone]" value="{{ $g['alt_phone'] ?? '' }}" inputmode="tel"></label>
    <label class="field">Email <span class="hint">needed for the parent app</span><input type="email" name="{{ $name }}[email]" value="{{ $g['email'] ?? '' }}"></label>
    <label class="field">Occupation<input type="text" name="{{ $name }}[occupation]" value="{{ $g['occupation'] ?? '' }}"></label>
    <input type="hidden" name="{{ $name }}[can_pickup]" value="0"><label class="check"><input type="checkbox" name="{{ $name }}[can_pickup]" value="1" @checked($g['can_pickup'] ?? true)> May pick up the student</label>
</div>
