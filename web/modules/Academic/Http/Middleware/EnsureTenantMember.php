<?php

namespace Modules\Academic\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use Modules\Academic\Support\CurrentMember;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in user must belong to this institution. API tokens must have been issued for it.
 * Optional roles: tenant.member:staff limits to staff roles (web console).
 */
class EnsureTenantMember
{
    public const STAFF = ['owner', 'admin', 'front_office', 'teacher', 'accountant'];

    public function __construct(private readonly CurrentMember $member)
    {
    }

    public function handle(Request $request, Closure $next, string ...$scope): Response
    {
        if (! TenantContext::check()) {
            throw new LogicException('tenant.member must run after the tenant is resolved.');
        }
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && ! $token->can('tenant:'.TenantContext::id())) {
            return $this->deny($request, 'This token was issued for another institution.');
        }
        if (! $this->member->membership()) {
            return $this->deny($request, 'You do not have access to this institution.');
        }
        if (in_array('staff', $scope, true) && ! in_array($this->member->role(), self::STAFF, true)) {
            return $this->deny($request, 'Parents and students use the Quick School AI mobile app.');
        }

        return $next($request);
    }

    private function deny(Request $request, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], 403);
        }
        auth('web')->logout();

        return response()->view('academic::denied', ['message' => $message], 403);
    }
}
