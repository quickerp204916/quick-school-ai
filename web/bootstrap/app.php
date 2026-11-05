<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Academic\Http\Middleware\EnsureTenantMember;
use Modules\Academic\Http\Middleware\ResolveTenant;
use Modules\Platform\Http\Middleware\AuthenticatePlatformToken;
use Modules\Platform\Http\Middleware\EnsurePlatformMfa;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        using: function () {
            // Institution app first, so its routes win on institution hosts.
            // TENANT_ROUTING=subdomain (production): https://{tenant}.quickschool.app/...
            // TENANT_ROUTING=path (local development): http://localhost:8000/i/{tenant}/...
            $bySubdomain = config('academic.routing', 'subdomain') === 'subdomain';
            $tenant = fn () => $bySubdomain
                ? Route::domain('{tenant}.'.config('platform.base_domain'))
                : Route::prefix('i/{tenant}');
            $tenant()->middleware(['web', 'tenant'])->name('app.')->group(base_path('routes/tenant.php'));
            $tenant()->prefix($bySubdomain ? 'api/v1' : 'i/{tenant}/api/v1')->middleware(['api', 'tenant'])->name('api.')
                ->group(base_path('routes/tenant-api.php'));

            // Health check for load balancers and Docker (the `health:` option is ignored when `using:` is set).
            Route::get('/up', fn () => response()->json(['status' => 'ok']))->name('health');

            // Super Admin console and platform API.
            Route::middleware('web')->group(base_path('routes/web.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Caddy/a load balancer: trust X-Forwarded-* from the proxies listed in TRUSTED_PROXIES
        // (comma-separated IPs/CIDRs, or * inside a private Docker network) so HTTPS URLs and client IPs are right.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->alias([
            'platform.mfa' => EnsurePlatformMfa::class,
            'platform.token' => AuthenticatePlatformToken::class,
            'tenant' => ResolveTenant::class,
            'tenant.member' => EnsureTenantMember::class,
        ]);
        // Resolve the institution before sessions, auth and route model binding, so all of them
        // run inside its tenant context.
        $middleware->prependToPriorityList(\Illuminate\Session\Middleware\StartSession::class, ResolveTenant::class);
        // Check the API token before rate limiting (limits are per token) and before route model
        // binding, so unknown IDs never leak to anonymous callers.
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\ThrottleRequests::class, AuthenticatePlatformToken::class);
        $middleware->redirectGuestsTo(fn (Request $request) => match (true) {
            $request->is('platform', 'platform/*') => route('platform.login'),
            $request->attributes->has('tenant') => route('app.login'),
            default => '/',
        });
        $middleware->redirectUsersTo(fn (Request $request) => match (true) {
            $request->is('platform', 'platform/*') => route('platform.dashboard'),
            $request->attributes->has('tenant') => route('app.dashboard'),
            default => '/',
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*', 'i/*/api/*') || $request->expectsJson());

        // Academic business rules ("Class 5 – A is full"): a form error on web pages, 422 on the API.
        $exceptions->render(function (\Modules\Academic\Exceptions\AcademicRuleException $e, Request $request) {
            if ($request->is('api/*', 'i/*/api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'errors' => [$e->field => [$e->getMessage()]]], 422);
            }

            return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        });
    })->create();
