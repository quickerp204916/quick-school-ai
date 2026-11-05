<?php

namespace Modules\Hr;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/hr.php'), 'hr');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(base_path('modules/Hr/resources/views'), 'hr');
        Route::model('employeeDocument', Models\EmployeeDocument::class);
        Route::model('leaveRequest', Models\LeaveRequest::class);
        Route::model('payrollRun', Models\PayrollRun::class);
        Route::model('payslip', Models\Payslip::class);
        Route::model('fnf', Models\FnfSettlement::class);
        Route::model('shift', Models\Shift::class);
        view()->composer('hr::*', fn ($view) => $view->with('money', fn (int $p) => \Modules\Accounting\Support\Amount::format($p)));
    }
}
