<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-factor sign-in · Quick School AI Super Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('css/platform.css') }}" rel="stylesheet">
</head>
<body>
<div class="login">
    <form class="card" method="post" action="{{ route('platform.mfa.verify') }}">
        @csrf
        <h1 style="font-size:20px">{{ $setup ? 'Set up two-factor sign-in' : 'Enter your sign-in code' }}</h1>
        @if ($setup)
            <p class="small" style="margin:0">Two-factor sign-in is required for all console staff. In Google Authenticator, Microsoft Authenticator or Authy, add an account with this setup key, then enter the 6-digit code it shows.</p>
            <div class="secret" aria-label="Setup key">{{ trim(chunk_split($secret, 4, ' ')) }}</div>
            <details class="small"><summary>Setup link for apps that accept one</summary><div class="secret" style="margin-top:6px">{{ $uri }}</div></details>
        @else
            <p class="small" style="margin:0">Open your authenticator app and enter the 6-digit code for Quick School AI.</p>
        @endif
        @if ($errors->any())<div class="alert err" role="alert">{{ $errors->first() }}</div>@endif
        <label class="field">6-digit code<input type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus autocomplete="one-time-code"></label>
        <button class="btn primary" type="submit">{{ $setup ? 'Turn on and continue' : 'Sign in' }}</button>
    </form>
    <form method="post" action="{{ route('platform.logout') }}" style="position:fixed;bottom:20px">@csrf<button class="btn sm" type="submit">Cancel and sign out</button></form>
</div>
</body>
</html>
