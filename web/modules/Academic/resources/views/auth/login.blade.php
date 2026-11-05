@component('academic::partials.guest', ['title' => 'Sign in', 'tenant' => $tenant])
    <h1>Sign in</h1>
    <form method="post" action="{{ route('app.login.attempt') }}" class="stack">@csrf
        <label class="field">Email<input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
        <label class="field">Password<input type="password" name="password" required autocomplete="current-password"></label>
        <label class="check"><input type="checkbox" name="remember" value="1"> Keep me signed in on this device</label>
        <button class="btn primary" type="submit">Sign in</button>
    </form>
    <p class="small muted" style="margin:0">Parents and students: use the Quick School AI app. Forgot your password? Ask the school office for a new invitation.</p>
@endcomponent
