{{-- Date range and branch filter. Keeps other query values. --}}
<form method="get" class="filters no-print">
    @foreach (request()->except(['from', 'to', 'branch', 'page', 'as_on']) as $k => $v)@if (is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
    @isset($asOn)<label class="field">As on<input type="date" name="as_on" value="{{ $asOn }}"></label>
    @else<label class="field">From<input type="date" name="from" value="{{ $from }}"></label><label class="field">To<input type="date" name="to" value="{{ $to }}"></label>@endisset
    @isset($branches)@if ($branches->count() > 1)<label class="field">Branch<select name="branch"><option value="">All branches</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(request('branch') === $b->id)>{{ $b->name }}</option>@endforeach</select></label>@endif @endisset
    {{ $slot ?? '' }}
    <button class="btn" type="submit">Show</button>
</form>
