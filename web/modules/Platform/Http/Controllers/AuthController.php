<?php

namespace Modules\Platform\Http\Controllers;

use App\Core\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\Platform\Services\PlatformAudit;

/** Email + password, then a mandatory TOTP code. First sign-in sets up the authenticator app. */
class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('platform::auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (! Auth::guard('platform')->attempt(['email' => strtolower($credentials['email']), 'password' => $credentials['password'], 'is_active' => true])) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Email or password is incorrect, or the account is disabled.']);
        }

        $request->session()->regenerate();
        $request->session()->put('platform.mfa_passed', false);

        return redirect()->route('platform.mfa');
    }

    public function showMfa(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('platform.mfa_passed')) {
            return redirect()->route('platform.dashboard');
        }

        $admin = $this->admin();
        $setup = $admin->mfa_confirmed_at === null;
        $secret = null;
        if ($setup) {
            $secret = $request->session()->get('platform.mfa_setup_secret') ?? Totp::generateSecret();
            $request->session()->put('platform.mfa_setup_secret', $secret);
        }

        return view('platform::auth.mfa', [
            'setup' => $setup,
            'secret' => $secret,
            'uri' => $secret ? Totp::provisioningUri($secret, $admin->email) : null,
        ]);
    }

    public function verifyMfa(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $admin = $this->admin();
        $setup = $admin->mfa_confirmed_at === null;
        $secret = $setup ? $request->session()->get('platform.mfa_setup_secret') : $admin->mfa_secret;

        if (! $secret || ! Totp::verify($secret, (string) $request->input('code'))) {
            return back()->withErrors(['code' => 'That code is not right. Check the time on your phone and try the newest code.']);
        }

        if ($setup) {
            $admin->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => now()])->save();
            $request->session()->forget('platform.mfa_setup_secret');
            PlatformAudit::record('admin.mfa_enabled', $admin, null, [], $admin);
        }

        $admin->forceFill(['last_login_at' => now()])->save();
        $request->session()->regenerate();
        $request->session()->put('platform.mfa_passed', true);
        PlatformAudit::record('admin.signed_in', $admin, null, [], $admin);

        return redirect()->intended(route('platform.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
