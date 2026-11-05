<?php

namespace Modules\Fees;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FeesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/fees.php'), 'fees');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(base_path('modules/Fees/resources/views'), 'fees');
        Route::model('feeHead', Models\FeeHead::class);
        Route::model('feeStructure', Models\FeeStructure::class);
        Route::model('feeConcession', Models\FeeConcession::class);
        Route::model('feePlan', Models\StudentFeePlan::class);
        Route::model('planConcession', Models\StudentFeeConcession::class);
        Route::model('feeInvoice', Models\FeeInvoice::class);
        Route::model('feePayment', Models\FeePayment::class);
        Route::model('feeRefund', Models\FeeRefund::class);
        Route::model('feeOrder', Models\FeePaymentOrder::class);
        if ($this->app->runningInConsole()) {
            $this->commands([Console\FeesDailyCommand::class]);
        }
        view()->composer('fees::*', function ($view) {
            $view->with('money', fn (int $p) => \Modules\Accounting\Support\Amount::format($p));
        });
    }
}
