<?php

namespace Modules\Accounting;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Accounting\Models;

class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/accounting.php'), 'accounting');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(base_path('modules/Accounting/resources/views'), 'accounting');
        Route::model('voucher', Models\Voucher::class);
        Route::model('ledger', Models\Ledger::class);
        Route::model('group', Models\AccountGroup::class);
        Route::model('bill', Models\Invoice::class);
        Route::model('costCentre', Models\CostCentre::class);
        Route::model('statementLine', Models\BankStatementLine::class);
        Route::model('financialYear', Models\FinancialYear::class);
        Route::model('asset', Models\FixedAsset::class);
        view()->composer('accounting::*', function ($view) {
            if (\App\Core\Tenancy\TenantContext::check()) {
                $view->with('t', fn (string $key) => \Modules\Academic\Support\Institution::term($key));
                $view->with('money', fn (int $p) => \Modules\Accounting\Support\Amount::format($p));
                $view->with('drcr', fn (int $p) => \Modules\Accounting\Support\Amount::drcr($p));
            }
        });
    }
}
