<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers as AC;

/*
| Accounts (inside the institution app, signed-in staff). Included from routes/tenant.php.
*/
Route::prefix('accounts')->name('accounts.')->middleware('can:academic.accounts.view')->group(function () {
    Route::get('/', [AC\AccountsController::class, 'dashboard'])->name('dashboard');

    // Vouchers
    Route::get('vouchers', [AC\VoucherController::class, 'index'])->name('vouchers.index');
    Route::get('approvals', [AC\VoucherController::class, 'approvals'])->name('approvals');
    Route::get('vouchers/create', [AC\VoucherController::class, 'create'])->middleware('can:academic.accounts.post')->name('vouchers.create');
    Route::post('vouchers', [AC\VoucherController::class, 'store'])->middleware('can:academic.accounts.post')->name('vouchers.store');
    Route::get('vouchers/{voucher}', [AC\VoucherController::class, 'show'])->name('vouchers.show');
    Route::get('vouchers/{voucher}/edit', [AC\VoucherController::class, 'edit'])->middleware('can:academic.accounts.post')->name('vouchers.edit');
    Route::put('vouchers/{voucher}', [AC\VoucherController::class, 'update'])->middleware('can:academic.accounts.post')->name('vouchers.update');
    Route::delete('vouchers/{voucher}', [AC\VoucherController::class, 'destroy'])->middleware('can:academic.accounts.post')->name('vouchers.destroy');
    Route::post('vouchers/{voucher}/submit', [AC\VoucherController::class, 'submit'])->middleware('can:academic.accounts.post')->name('vouchers.submit');
    Route::post('vouchers/{voucher}/approve', [AC\VoucherController::class, 'approve'])->middleware('can:academic.accounts.approve')->name('vouchers.approve');
    Route::post('vouchers/{voucher}/reject', [AC\VoucherController::class, 'reject'])->middleware('can:academic.accounts.approve')->name('vouchers.reject');
    Route::post('vouchers/{voucher}/reverse', [AC\VoucherController::class, 'reverse'])->middleware('can:academic.accounts.approve')->name('vouchers.reverse');

    // Chart of accounts and opening balances
    Route::get('chart', [AC\ChartController::class, 'index'])->name('chart');
    Route::middleware('can:academic.accounts.configure')->group(function () {
        Route::post('chart/groups', [AC\ChartController::class, 'storeGroup'])->name('groups.store');
        Route::put('chart/groups/{group}', [AC\ChartController::class, 'updateGroup'])->name('groups.update');
        Route::post('chart/ledgers', [AC\ChartController::class, 'storeLedger'])->name('ledgers.store');
        Route::get('chart/ledgers/{ledger}/edit', [AC\ChartController::class, 'editLedger'])->name('ledgers.edit');
        Route::put('chart/ledgers/{ledger}', [AC\ChartController::class, 'updateLedger'])->name('ledgers.update');
        Route::get('opening', [AC\ChartController::class, 'opening'])->name('opening');
        Route::post('opening', [AC\ChartController::class, 'saveOpening'])->name('opening.save');
    });

    // Invoices, notes and expenses
    Route::get('invoices', [AC\InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('expenses', [AC\InvoiceController::class, 'expenses'])->name('expenses');
    Route::get('invoices/create', [AC\InvoiceController::class, 'create'])->middleware('can:academic.accounts.post')->name('invoices.create');
    Route::post('invoices', [AC\InvoiceController::class, 'store'])->middleware('can:academic.accounts.post')->name('invoices.store');
    Route::get('invoices/{bill}', [AC\InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('invoices/{bill}/submit', [AC\InvoiceController::class, 'submit'])->middleware('can:academic.accounts.post')->name('invoices.submit');
    Route::delete('invoices/{bill}', [AC\InvoiceController::class, 'destroy'])->middleware('can:academic.accounts.post')->name('invoices.destroy');

    // Bank reconciliation
    Route::get('bank', [AC\BankController::class, 'index'])->name('bank');
    Route::middleware('can:academic.accounts.post')->group(function () {
        Route::post('bank/import', [AC\BankController::class, 'import'])->name('bank.import');
        Route::post('bank/auto-match', [AC\BankController::class, 'autoMatch'])->name('bank.auto');
        Route::post('bank/lines/{statementLine}/match', [AC\BankController::class, 'match'])->name('bank.match');
        Route::delete('bank/lines/{statementLine}/match', [AC\BankController::class, 'unmatch'])->name('bank.unmatch');
    });

    // Budgets and fixed assets
    Route::get('budgets', [AC\PlanningController::class, 'budgets'])->name('budgets');
    Route::post('budgets', [AC\PlanningController::class, 'storeBudget'])->middleware('can:academic.accounts.configure')->name('budgets.store');
    Route::get('assets', [AC\PlanningController::class, 'assets'])->name('assets');
    Route::post('assets', [AC\PlanningController::class, 'storeAsset'])->middleware('can:academic.accounts.configure')->name('assets.store');
    Route::post('assets/depreciation', [AC\PlanningController::class, 'depreciate'])->middleware('can:academic.accounts.post')->name('assets.depreciate');

    // Settings
    Route::get('settings', [AC\AccountsController::class, 'settings'])->name('settings');
    Route::middleware('can:academic.accounts.configure')->group(function () {
        Route::put('settings', [AC\AccountsController::class, 'saveSettings'])->name('settings.save');
        Route::post('settings/lock', [AC\AccountsController::class, 'lock'])->name('settings.lock');
        Route::post('settings/years', [AC\AccountsController::class, 'addYear'])->name('years.store');
        Route::post('settings/years/{financialYear}/close', [AC\AccountsController::class, 'closeYear'])->name('years.close');
        Route::post('settings/cost-centres', [AC\AccountsController::class, 'storeCostCentre'])->name('cost-centres.store');
        Route::put('settings/cost-centres/{costCentre}', [AC\AccountsController::class, 'updateCostCentre'])->name('cost-centres.update');
        Route::post('settings/tds', [AC\AccountsController::class, 'storeTds'])->name('tds.store');
    });

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('trial-balance', [AC\ReportController::class, 'trialBalance'])->name('trial-balance');
        Route::get('profit-loss', [AC\ReportController::class, 'profitLoss'])->name('profit-loss');
        Route::get('balance-sheet', [AC\ReportController::class, 'balanceSheet'])->name('balance-sheet');
        Route::get('cash-flow', [AC\ReportController::class, 'cashFlow'])->name('cash-flow');
        Route::get('ledger', [AC\ReportController::class, 'ledger'])->name('ledger');
        Route::get('cash-book', [AC\ReportController::class, 'cashBook'])->name('cash-book');
        Route::get('bank-book', [AC\ReportController::class, 'bankBook'])->name('bank-book');
        Route::get('day-book', [AC\ReportController::class, 'dayBook'])->name('day-book');
        Route::get('budget', [AC\ReportController::class, 'budget'])->name('budget');
        Route::get('cost-centres', [AC\ReportController::class, 'costCentres'])->name('cost-centres');
        Route::get('outstanding', [AC\ReportController::class, 'outstanding'])->name('outstanding');
    });
});
