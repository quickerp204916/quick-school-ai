<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Modules\Platform\Models\PlatformAdmin;
use Modules\Platform\Models\PlatformApiToken;
use Modules\Platform\Services\PlatformAudit;

class AdminController extends Controller
{
    public function index(): View
    {
        return view('platform::admins.index', [
            'admins' => PlatformAdmin::orderBy('name')->get(),
            'tokens' => PlatformApiToken::with('admin')->latest()->get(),
            'roles' => config('platform.roles'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:191', 'unique:platform_admins,email'],
            'role' => ['required', Rule::in(array_keys(config('platform.roles')))],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()],
        ]);

        $admin = PlatformAdmin::create($data + ['is_active' => true]);
        PlatformAudit::record('admin.created', $admin, null, ['email' => $admin->email, 'role' => $admin->role]);

        return back()->with('status', "{$admin->name} added. They set up two-factor sign-in the first time they log in.");
    }

    public function toggle(PlatformAdmin $admin): RedirectResponse
    {
        if ($admin->is($this->admin())) {
            return back()->withErrors(['action' => 'You cannot disable your own account.']);
        }
        if ($admin->is_active && $admin->role === 'super_admin' && PlatformAdmin::where('role', 'super_admin')->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['action' => 'Keep at least one active super admin.']);
        }

        $admin->update(['is_active' => ! $admin->is_active]);
        PlatformAudit::record($admin->is_active ? 'admin.enabled' : 'admin.disabled', $admin);

        return back()->with('status', $admin->name.($admin->is_active ? ' can sign in again.' : ' can no longer sign in.'));
    }

    public function resetMfa(PlatformAdmin $admin): RedirectResponse
    {
        $admin->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null])->save();
        PlatformAudit::record('admin.mfa_reset', $admin);

        return back()->with('status', "Two-factor sign-in reset for {$admin->name}. They set it up again at next login.");
    }

    public function storeToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(['read', 'write'])],
            'expires_in_days' => ['nullable', 'integer', 'between:1,365'],
        ]);

        [$token, $plain] = PlatformApiToken::issue($this->admin(), $data['name'], $data['abilities'],
            isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null);
        PlatformAudit::record('api_token.created', $token, null, ['name' => $token->name, 'abilities' => $token->abilities]);

        return back()->with('status', 'API token created. Copy it now: it will not be shown again.')->with('plain_token', $plain);
    }

    public function revokeToken(PlatformApiToken $token): RedirectResponse
    {
        $token->update(['revoked_at' => now()]);
        PlatformAudit::record('api_token.revoked', $token, null, ['name' => $token->name]);

        return back()->with('status', "Token “{$token->name}” revoked.");
    }
}
