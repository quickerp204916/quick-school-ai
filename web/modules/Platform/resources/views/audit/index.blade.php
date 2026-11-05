@extends('platform::layout')
@section('title', 'Audit log')
@section('content')
<div class="head"><div><h1>Audit log</h1><p class="muted" style="margin:4px 0 0">Every action in this console and the platform API. Entries cannot be edited or deleted.</p></div></div>
<form class="filters" method="get">
    <label class="field">Action starts with<input type="text" name="action" value="{{ request('action') }}" placeholder="tenant."></label>
    <label class="field">Staff<select name="admin"><option value="">Anyone</option>@foreach ($admins as $a)<option value="{{ $a->id }}" @selected(request('admin') === $a->id)>{{ $a->name }}</option>@endforeach</select></label>
    <label class="field">From<input type="date" name="from" value="{{ request('from') }}"></label>
    <label class="field">To<input type="date" name="to" value="{{ request('to') }}"></label>
    <button class="btn" type="submit">Filter</button>
</form>
<section class="card"><div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Staff</th><th>Via</th><th>Action</th><th>Institution</th><th>Details</th></tr></thead><tbody>
    @forelse ($logs as $log)
        <tr><td class="small" style="white-space:nowrap">{{ $log->created_at->format('j M Y, H:i:s') }}</td><td>{{ $log->admin?->name ?? 'System' }}</td><td class="small">{{ $log->via }}</td>
            <td><code>{{ $log->action }}</code></td><td>@if ($log->tenant)<a href="{{ route('platform.tenants.show', $log->tenant) }}">{{ $log->tenant->name }}</a>@endif</td>
            <td class="small" style="max-width:380px;overflow-wrap:anywhere">{{ $log->changes ? json_encode($log->changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '' }}</td></tr>
    @empty <tr><td colspan="6" class="muted">No entries match.</td></tr> @endforelse
    </tbody></table></div>
    @if ($logs->hasPages())<div class="pager small">{!! $logs->links('pagination::simple-default') !!}</div>@endif
</section>
@endsection
