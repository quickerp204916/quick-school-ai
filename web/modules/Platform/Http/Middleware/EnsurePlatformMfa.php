<?php

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Every console page needs two-factor sign-in completed in this session, and an active account. */
class EnsurePlatformMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('platform');

        if (! $admin || ! $admin->is_active) {
            Auth::guard('platform')->logout();
            $request->session()->invalidate();

            return redirect()->route('platform.login')->withErrors(['email' => 'Your account is not active.']);
        }

        if (! $request->session()->get('platform.mfa_passed')) {
            return redirect()->route('platform.mfa');
        }

        return $next($request);
    }
}
