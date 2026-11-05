<?php

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\Api\PlatformApiController;

/*
| Platform API v1. Bearer token from Super Admin console › Staff › API tokens.
| Writes that create money or tenants require an Idempotency-Key header.
*/
Route::prefix('v1/platform')->name('api.platform.')->group(function () {
    Route::middleware(['platform.token:read', 'throttle:platform-api'])->group(function () {
        Route::get('tenants', [PlatformApiController::class, 'tenants'])->name('tenants.index');
        Route::get('tenants/{tenant}', [PlatformApiController::class, 'tenant'])->name('tenants.show');
        Route::get('plans', [PlatformApiController::class, 'plans'])->name('plans.index');
        Route::get('invoices', [PlatformApiController::class, 'invoices'])->name('invoices.index');
    });

    Route::middleware(['platform.token:write', 'throttle:platform-api'])->group(function () {
        Route::post('tenants', [PlatformApiController::class, 'storeTenant'])->name('tenants.store');
        Route::post('tenants/{tenant}/suspend', [PlatformApiController::class, 'suspend'])->name('tenants.suspend');
        Route::post('tenants/{tenant}/reactivate', [PlatformApiController::class, 'reactivate'])->name('tenants.reactivate');
        Route::post('invoices/{invoice}/payments', [PlatformApiController::class, 'storePayment'])->name('invoices.payments.store');
    });
});
