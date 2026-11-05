@if ($p->hasPages())
<nav class="pager small" aria-label="Pages">
    <span class="muted">{{ $p->firstItem() }}–{{ $p->lastItem() }} of {{ $p->total() }}</span>
    @if ($p->previousPageUrl())<a class="btn sm" href="{{ $p->previousPageUrl() }}">Previous</a>@endif
    @if ($p->nextPageUrl())<a class="btn sm" href="{{ $p->nextPageUrl() }}">Next</a>@endif
</nav>
@endif
