<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Platform\Http\Middleware\AuthenticatePlatformToken;
use Modules\Platform\Http\Middleware\EnsurePlatformMfa;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'platform.mfa' => EnsurePlatformMfa::class,
            'platform.token' => AuthenticatePlatformToken::class,
        ]);
        // Check the API token before rate limiting (limits are per token) and before route model
        // binding, so unknown IDs never leak to anonymous callers.
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\ThrottleRequests::class, AuthenticatePlatformToken::class);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('platform', 'platform/*')
            ? route('platform.login')
            : '/');
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('platform', 'platform/*')
            ? route('platform.dashboard')
            : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
