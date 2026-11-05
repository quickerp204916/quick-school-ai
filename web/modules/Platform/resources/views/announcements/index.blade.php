@extends('platform::layout')
@section('title', 'Announcements')
@section('content')
<div class="head"><div><h1>Announcements</h1><p class="muted" style="margin:4px 0 0">Shown to institutions' staff inside the app, for maintenance windows and new features.</p></div>
    <a class="btn primary" href="{{ route('platform.announcements.create') }}">New announcement</a></div>
<section class="card"><div class="table-wrap"><table>
    <thead><tr><th>Title</th><th>Level</th><th>Audience</th><th>Shown</th><th></th></tr></thead><tbody>
    @forelse ($announcements as $a)
        <tr><td><b>{{ $a->title }}</b><div class="small muted">{{ \Illuminate\Support\Str::limit($a->body, 90) }}</div></td>
            <td><span class="chip {{ $a->level }}">{{ $a->level }}</span></td>
            <td>{{ $a->audience === 'all' ? 'All institutions' : ($a->plan?->name ?? '—').' plan' }}</td>
            <td class="small">{{ $a->starts_at->format('j M Y H:i') }} to {{ $a->ends_at?->format('j M Y H:i') ?? 'no end' }}</td>
            <td style="white-space:nowrap"><a class="btn sm" href="{{ route('platform.announcements.edit', $a) }}">Edit</a>
                <form class="inline" method="post" action="{{ route('platform.announcements.destroy', $a) }}">@csrf @method('delete')<button class="btn sm danger" type="submit">Delete</button></form></td></tr>
    @empty <tr><td colspan="5" class="muted">No announcements.</td></tr> @endforelse
    </tbody></table></div>
    @if ($announcements->hasPages())<div class="pager small">{!! $announcements->links('pagination::simple-default') !!}</div>@endif
</section>
@endsection
