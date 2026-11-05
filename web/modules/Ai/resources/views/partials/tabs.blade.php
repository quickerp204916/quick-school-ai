{{-- AI module tabs --}}
<nav class="toolbar no-print" aria-label="AI">
    <a class="btn sm {{ request()->routeIs('app.ai.index', 'app.ai.chat') ? 'primary' : '' }}" href="{{ route('app.ai.index') }}">Assistants</a>
    @can('academic.ai.approve')<a class="btn sm {{ request()->routeIs('app.ai.approvals') ? 'primary' : '' }}" href="{{ route('app.ai.approvals') }}">Approvals</a>@endcan
    <a class="btn sm {{ request()->routeIs('app.ai.documents*') ? 'primary' : '' }}" href="{{ route('app.ai.documents') }}">Documents</a>
    @can('academic.accounts.view')<a class="btn sm {{ request()->routeIs('app.ai.anomalies') ? 'primary' : '' }}" href="{{ route('app.ai.anomalies') }}">Unusual entries</a>@endcan
    @if (auth()->user()->can('academic.fees.view') || auth()->user()->can('academic.admissions.manage'))<a class="btn sm {{ request()->routeIs('app.ai.calls') ? 'primary' : '' }}" href="{{ route('app.ai.calls') }}">Call log</a>@endif
    @can('academic.ai.configure')
        <a class="btn sm {{ request()->routeIs('app.ai.settings') ? 'primary' : '' }}" href="{{ route('app.ai.settings') }}">Settings</a>
        <a class="btn sm {{ request()->routeIs('app.ai.prompts') ? 'primary' : '' }}" href="{{ route('app.ai.prompts') }}">Prompts</a>
        <a class="btn sm {{ request()->routeIs('app.ai.knowledge') ? 'primary' : '' }}" href="{{ route('app.ai.knowledge') }}">Knowledge base</a>
        <a class="btn sm {{ request()->routeIs('app.ai.usage') ? 'primary' : '' }}" href="{{ route('app.ai.usage') }}">Usage and credits</a>
    @endcan
</nav>
