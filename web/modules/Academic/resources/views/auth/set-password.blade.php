@component('academic::partials.guest', ['title' => 'Set your password', 'tenant' => $tenant])
    <h1>Set your password</h1>
    <form method="post" action="{{ route('app.password.store') }}" class="stack">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label class="field">Email<input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username"></label>
        <label class="field">New password <span class="hint">At least 10 characters with upper and lower case letters and a number.</span>
            <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label class="field">Repeat password<input type="password" name="password_confirmation" required autocomplete="new-password"></label>
        <button class="btn primary" type="submit">Set password</button>
    </form>
@endcomponent
