<?php

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Platform\Models\PlatformApiToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication for the platform API.
 * Usage: platform.token:read or platform.token:write
 */
class AuthenticatePlatformToken
{
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $plain = $request->bearerToken();
        if (! $plain) {
            return $this->error(401, 'unauthenticated', 'Send an API token in the Authorization: Bearer header.');
        }

        $token = PlatformApiToken::with('admin')->where('token_hash', hash('sha256', $plain))->first();
        if (! $token || ! $token->isUsable() || ! $token->admin?->is_active) {
            return $this->error(401, 'invalid_token', 'This API token is invalid, expired or revoked.');
        }
        if (! $token->can($ability)) {
            return $this->error(403, 'insufficient_scope', "This token does not have the {$ability} permission.");
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('platform_token_id', $token->id);
        $request->attributes->set('platform_token', $token);
        Auth::shouldUse('platform');
        Auth::guard('platform')->setUser($token->admin);

        return $next($request);
    }

    private function error(int $status, string $code, string $message): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
