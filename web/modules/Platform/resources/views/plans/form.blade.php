@extends('platform::layout')
@section('title', $plan->exists ? 'Edit '.$plan->name : 'New plan')
@section('content')
<div class="head"><h1>{{ $plan->exists ? 'Edit '.$plan->name : 'New plan' }}</h1><a class="btn" href="{{ route('platform.plans.index') }}">Back</a></div>
<form class="card" method="post" action="{{ $plan->exists ? route('platform.plans.update', $plan) : route('platform.plans.store') }}">
    @csrf @if ($plan->exists) @method('put') @endif
    <div class="form">
        <label class="field">Name<input type="text" name="name" value="{{ old('name', $plan->name) }}" required maxlength="100"></label>
        <label class="field">Code<span class="hint">Lower case, used by the API</span><input type="text" name="code" value="{{ old('code', $plan->code) }}" required pattern="[a-z0-9_-]{2,40}"></label>
        <label class="field">Monthly price (₹, before GST)<input type="text" name="price_monthly" value="{{ old('price_monthly', $plan->price_monthly) }}" required inputmode="decimal"></label>
        <label class="field">Yearly price (₹, before GST)<input type="text" name="price_yearly" value="{{ old('price_yearly', $plan->price_yearly) }}" required inputmode="decimal"></label>
        <label class="field">Trial days<input type="number" name="trial_days" value="{{ old('trial_days', $plan->trial_days) }}" min="0" max="90" required></label>
        <label class="field">Sort order<input type="number" name="sort_order" value="{{ old('sort_order', $plan->sort_order) }}" min="0" max="999"></label>
        <label class="field wide">Description<input type="text" name="description" value="{{ old('description', $plan->description) }}" maxlength="500"></label>
        <label class="check"><input type="checkbox" name="is_public" value="1" @checked(old('is_public', $plan->is_public))> Shown on the public pricing page</label>
        <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan->is_active))> Active (can be sold)</label>

        <h2 class="wide" style="margin-top:8px">Features and limits</h2>
        @foreach ($catalogue as $key => $def)
            @php($v = old('features.'.$key, array_key_exists($key, $values) ? ($def['type'] === 'bool' ? ($values[$key] ? '1' : '') : ($values[$key] === null ? 'unlimited' : $values[$key])) : ''))
            @if ($def['type'] === 'bool')
                <label class="check"><input type="checkbox" name="features[{{ $key }}]" value="1" @checked($v === '1' || $v === true)> {{ $def['label'] }}</label>
            @else
                <label class="field">{{ $def['label'] }}<span class="hint">Number, or “unlimited”</span><input type="text" name="features[{{ $key }}]" value="{{ $v }}" pattern="[0-9]{1,9}|unlimited"></label>
            @endif
        @endforeach
    </div>
    <div style="margin-top:18px"><button class="btn primary" type="submit">Save plan</button></div>
</form>
@endsection
