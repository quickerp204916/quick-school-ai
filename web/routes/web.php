<?php

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\AdminController;
use Modules\Platform\Http\Controllers\AnnouncementController;
use Modules\Platform\Http\Controllers\AuditLogController;
use Modules\Platform\Http\Controllers\AuthController;
use Modules\Platform\Http\Controllers\DashboardController;
use Modules\Platform\Http\Controllers\InvoiceController;
use Modules\Platform\Http\Controllers\PlanController;
use Modules\Platform\Http\Controllers\SupportSessionController;
use Modules\Platform\Http\Controllers\TenantController;

Route::get('/', fn () => redirect()->route('platform.dashboard'));

/*
| On-demand TLS check for Caddy: certificates are only issued for the platform host and for
| <code>.<base_domain> where <code> is a live institution. Reached only on Caddy's internal port.
*/
Route::get('internal/tls-check', function (\Illuminate\Http\Request $request) {
    $domain = strtolower((string) $request->query('domain'));
    $base = strtolower((string) config('platform.base_domain'));
    if ($domain === $base || $domain === 'www.'.$base) {
        return response('ok');
    }
    $suffix = '.'.$base;
    if (! str_ends_with($domain, $suffix)) {
        abort(404);
    }
    $code = substr($domain, 0, -strlen($suffix));
    $live = $code !== '' && ! str_contains($code, '.')
        && \Modules\Platform\Models\Tenant::where('code', $code)->whereNotIn('status', ['archived'])->exists();

    return $live ? response('ok') : abort(404);
})->middleware('throttle:120,1')->name('internal.tls-check');

/*
| Super Admin console: /platform
*/
Route::prefix('platform')->name('platform.')->group(function () {
    Route::middleware('guest:platform')->group(function () {
        Route::get('login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:platform-login')->name('login.attempt');
    });

    Route::middleware('auth:platform')->group(function () {
        Route::get('mfa', [AuthController::class, 'showMfa'])->name('mfa');
        Route::post('mfa', [AuthController::class, 'verifyMfa'])->middleware('throttle:platform-mfa')->name('mfa.verify');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });

    Route::middleware(['auth:platform', 'platform.mfa', 'can:platform.view'])->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        // Institutions
        Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::get('tenants/create', [TenantController::class, 'create'])->middleware('can:platform.tenants.manage')->name('tenants.create');
        Route::post('tenants', [TenantController::class, 'store'])->middleware('can:platform.tenants.manage')->name('tenants.store');
        Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
        Route::get('tenants/{tenant}/edit', [TenantController::class, 'edit'])->middleware('can:platform.tenants.manage')->name('tenants.edit');
        Route::put('tenants/{tenant}', [TenantController::class, 'update'])->middleware('can:platform.tenants.manage')->name('tenants.update');
        Route::post('tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->middleware('can:platform.tenants.manage')->name('tenants.suspend');
        Route::post('tenants/{tenant}/reactivate', [TenantController::class, 'reactivate'])->middleware('can:platform.tenants.manage')->name('tenants.reactivate');
        Route::post('tenants/{tenant}/archive', [TenantController::class, 'archive'])->middleware('can:platform.tenants.manage')->name('tenants.archive');
        Route::post('tenants/{tenant}/overrides', [TenantController::class, 'storeOverride'])->middleware('can:platform.tenants.manage')->name('tenants.overrides.store');
        Route::delete('tenants/{tenant}/overrides/{override}', [TenantController::class, 'destroyOverride'])->middleware('can:platform.tenants.manage')->name('tenants.overrides.destroy');

        // Billing for one institution
        Route::post('tenants/{tenant}/subscription', [TenantController::class, 'changePlan'])->middleware('can:platform.billing')->name('tenants.subscription.change');
        Route::post('tenants/{tenant}/subscription/cancel', [TenantController::class, 'cancelSubscription'])->middleware('can:platform.billing')->name('tenants.subscription.cancel');
        Route::post('tenants/{tenant}/subscription/resume', [TenantController::class, 'resumeSubscription'])->middleware('can:platform.billing')->name('tenants.subscription.resume');
        Route::post('tenants/{tenant}/credits', [TenantController::class, 'grantCredits'])->middleware('can:platform.billing')->name('tenants.credits.store');

        // Support access
        Route::get('support', [SupportSessionController::class, 'index'])->middleware('can:platform.support')->name('support.index');
        Route::post('tenants/{tenant}/support-sessions', [SupportSessionController::class, 'store'])->middleware('can:platform.support')->name('support.store');
        Route::post('support-sessions/{session}/end', [SupportSessionController::class, 'end'])->middleware('can:platform.support')->name('support.end');

        // Plans
        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::middleware('can:platform.billing')->group(function () {
            Route::get('plans/create', [PlanController::class, 'create'])->name('plans.create');
            Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
            Route::get('plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
            Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
        });

        // Invoices
        Route::middleware('can:platform.billing')->group(function () {
            Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
            Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'storePayment'])->name('invoices.payments.store');
            Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        });

        // Announcements to institutions
        Route::middleware('can:platform.announcements')->group(function () {
            Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
            Route::get('announcements/create', [AnnouncementController::class, 'create'])->name('announcements.create');
            Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
            Route::get('announcements/{announcement}/edit', [AnnouncementController::class, 'edit'])->name('announcements.edit');
            Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
            Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');
        });

        Route::get('audit', AuditLogController::class)->name('audit.index');

        // Platform staff and API tokens
        Route::middleware('can:platform.admins')->group(function () {
            Route::get('admins', [AdminController::class, 'index'])->name('admins.index');
            Route::post('admins', [AdminController::class, 'store'])->name('admins.store');
            Route::post('admins/{admin}/toggle', [AdminController::class, 'toggle'])->name('admins.toggle');
            Route::post('admins/{admin}/reset-mfa', [AdminController::class, 'resetMfa'])->name('admins.reset-mfa');
            Route::post('api-tokens', [AdminController::class, 'storeToken'])->name('tokens.store');
            Route::post('api-tokens/{token}/revoke', [AdminController::class, 'revokeToken'])->name('tokens.revoke');
        });
    });
});
