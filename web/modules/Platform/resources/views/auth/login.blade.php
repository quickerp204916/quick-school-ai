<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Quick School AI Super Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('css/platform.css') }}" rel="stylesheet">
</head>
<body>
<div class="login">
    <form class="card" method="post" action="{{ route('platform.login.attempt') }}">
        @csrf
        <div class="brand" style="border:0;padding:0;margin:0"><span class="mark">Q</span><div><b style="color:var(--navy-900)">QUICK SCHOOL AI</b><span>Super Admin console</span></div></div>
        @if ($errors->any())<div class="alert err" role="alert">{{ $errors->first() }}</div>@endif
        <label class="field">Work email<input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
        <label class="field">Password<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn primary" type="submit">Continue</button>
        <p class="small muted" style="margin:0">Next you will enter a code from your authenticator app.</p>
    </form>
</div>
</body>
</html>
