<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Academic\Support\CurrentMember;
use Modules\Academic\Support\Institution;
use Modules\Identity\Models\TenantMembership;
use Modules\Identity\Models\User;

/** Sign-in for institution staff, and setting a password from an invitation link. */
class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('academic::auth.login', ['tenant' => Institution::tenant()]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', strtolower($data['email']))->first();
        $member = $user ? TenantMembership::where('user_id', $user->id)->where('status', 'active')->first() : null;

        if (! $user || ! $member || ! Auth::guard('web')->attempt(['email' => $user->email, 'password' => $data['password']], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'The email or password is wrong, or this account does not belong to '.Institution::tenant()->name.'.']);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('app.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('app.login');
    }

    public function showSetPassword(Request $request): View
    {
        return view('academic::auth.set-password', ['tenant' => Institution::tenant(), 'token' => (string) $request->query('token'), 'email' => (string) $request->query('email')]);
    }

    public function setPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->mixedCase()->numbers()],
        ]);
        $status = Password::broker('users')->reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->setRememberToken(\Illuminate\Support\Str::random(60));
            $user->save();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This link has expired or was already used. Ask the school office for a new invitation.']);
        }

        return redirect()->route('app.login')->with('status', 'Password set. Sign in with your new password.');
    }
}
