<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ $tenant->name }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('css/platform.css') }}" rel="stylesheet">
</head>
<body><div class="login"><div class="card">
    <div class="brand" style="border:0;padding:0;margin:0"><span class="mark">Q</span><div><b style="color:var(--navy-900)">{{ $tenant->name }}</b><span>Quick School AI</span></div></div>
    @if (session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
    @if (isset($errors) && $errors->any())<div class="alert err" role="alert"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
    {!! $slot !!}
</div></div></body>
</html>
