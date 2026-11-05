<?php

namespace Modules\Academic\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Modules\Platform\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the institution from the subdomain ({code}.quickschool.app) and runs the rest of the
 * request inside its tenant context, so every query and write is limited to it.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = strtolower((string) $request->route('tenant'));
        $tenant = Tenant::where('code', $code)->first();

        if (! $tenant || $tenant->status === 'archived') {
            abort(404);
        }
        if ($tenant->status === 'suspended') {
            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['message' => 'This institution’s account is suspended. Contact the school office.'], 403)
                : response()->view('academic::suspended', ['tenant' => $tenant], 403);
        }

        $request->route()->forgetParameter('tenant');
        URL::defaults(['tenant' => $tenant->code]);
        $request->attributes->set('tenant', $tenant);

        return TenantContext::run($tenant, fn () => $next($request));
    }
}
