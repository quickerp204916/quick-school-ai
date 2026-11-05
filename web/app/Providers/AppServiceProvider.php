<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Console\BillingRunCommand;
use Modules\Platform\Console\CreateAdminCommand;
use Modules\Platform\Models\PlatformAdmin;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(base_path('modules/Platform/resources/views'), 'platform');

        // PostgreSQL rolls back app.tenant_id with the transaction; put the current tenant back.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class,
            fn () => \App\Core\Tenancy\TenantContext::syncPostgres());

        if ($this->app->runningInConsole()) {
            $this->commands([BillingRunCommand::class, CreateAdminCommand::class]);
        }

        // Super Admin console permissions, by platform role.
        $is = fn ($user, array $roles) => $user instanceof PlatformAdmin && $user->hasRole(...$roles);
        Gate::define('platform.view', fn ($user) => $is($user, ['super_admin', 'billing', 'support']));
        Gate::define('platform.tenants.manage', fn ($user) => $is($user, ['super_admin']));
        Gate::define('platform.billing', fn ($user) => $is($user, ['super_admin', 'billing']));
        Gate::define('platform.support', fn ($user) => $is($user, ['super_admin', 'support']));
        Gate::define('platform.announcements', fn ($user) => $is($user, ['super_admin', 'support']));
        Gate::define('platform.admins', fn ($user) => $is($user, ['super_admin']));

        RateLimiter::for('platform-login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('platform-mfa', fn (Request $request) => Limit::perMinute(5)->by(($request->user('platform')?->id ?? 'guest').'|'.$request->ip()));
        RateLimiter::for('platform-api', fn (Request $request) => Limit::perMinute(120)->by($request->attributes->get('platform_token_id') ?? $request->ip()));
    }
}
