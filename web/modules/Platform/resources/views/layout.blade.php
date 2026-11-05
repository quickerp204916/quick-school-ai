<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Console') · Quick School AI Super Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('css/platform.css') }}" rel="stylesheet">
</head>
<body>
<div class="shell">
    <nav class="side" aria-label="Super Admin">
        <div class="brand"><span class="mark">Q</span><div><b>QUICK SCHOOL AI</b><span>Super Admin</span></div></div>
        @php($r = request()->route()?->getName() ?? '')
        <a class="nav {{ $r === 'platform.dashboard' ? 'on' : '' }}" href="{{ route('platform.dashboard') }}">Dashboard</a>
        <a class="nav {{ str_starts_with($r, 'platform.tenants') ? 'on' : '' }}" href="{{ route('platform.tenants.index') }}">Institutions</a>
        <a class="nav {{ str_starts_with($r, 'platform.plans') ? 'on' : '' }}" href="{{ route('platform.plans.index') }}">Plans</a>
        @can('platform.billing')
            <a class="nav {{ str_starts_with($r, 'platform.invoices') ? 'on' : '' }}" href="{{ route('platform.invoices.index') }}">Invoices</a>
        @endcan
        @can('platform.support')
            <a class="nav {{ str_starts_with($r, 'platform.support') ? 'on' : '' }}" href="{{ route('platform.support.index') }}">Support access</a>
        @endcan
        @can('platform.announcements')
            <a class="nav {{ str_starts_with($r, 'platform.announcements') ? 'on' : '' }}" href="{{ route('platform.announcements.index') }}">Announcements</a>
        @endcan
        <a class="nav {{ str_starts_with($r, 'platform.audit') ? 'on' : '' }}" href="{{ route('platform.audit.index') }}">Audit log</a>
        @can('platform.admins')
            <a class="nav {{ str_starts_with($r, 'platform.admins') ? 'on' : '' }}" href="{{ route('platform.admins.index') }}">Staff and API tokens</a>
        @endcan
        <div class="who">
            <b>{{ auth('platform')->user()->name }}</b>{{ auth('platform')->user()->roleLabel() }}
            <form method="post" action="{{ route('platform.logout') }}">@csrf<button class="link" type="submit">Sign out</button></form>
        </div>
    </nav>
    <main class="main">
        @if (session('status'))
            <div class="alert ok" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert err" role="alert"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif
        @yield('content')
    </main>
</div>
</body>
</html>
