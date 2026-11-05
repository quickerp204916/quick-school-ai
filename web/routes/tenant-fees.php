<?php

use Illuminate\Support\Facades\Route;
use Modules\Fees\Http\Controllers as F;

/*
| Fees (inside the institution app, signed-in staff). Included from routes/tenant.php.
*/
Route::prefix('fees')->name('fees.')->middleware('can:academic.fees.view')->group(function () {
    Route::get('/', [F\FeesController::class, 'dashboard'])->name('dashboard');
    Route::post('run', [F\FeesController::class, 'run'])->middleware('can:academic.fees.configure')->name('run');

    // Setup: heads, concessions, gateways and reminders
    Route::middleware('can:academic.fees.configure')->group(function () {
        Route::get('setup', [F\SetupController::class, 'index'])->name('setup');
        Route::post('heads', [F\SetupController::class, 'storeHead'])->name('heads.store');
        Route::put('heads/{feeHead}', [F\SetupController::class, 'updateHead'])->name('heads.update');
        Route::post('concessions', [F\SetupController::class, 'storeConcession'])->name('concessions.store');
        Route::put('concessions/{feeConcession}', [F\SetupController::class, 'updateConcession'])->name('concessions.update');
        Route::put('settings', [F\SetupController::class, 'updateSettings'])->middleware('can:academic.fees.approve')->name('settings');
        Route::get('structures', [F\StructureController::class, 'index'])->name('structures.index');
        Route::get('structures/create', [F\StructureController::class, 'create'])->name('structures.create');
        Route::post('structures', [F\StructureController::class, 'store'])->name('structures.store');
        Route::get('structures/{feeStructure}/edit', [F\StructureController::class, 'edit'])->name('structures.edit');
        Route::put('structures/{feeStructure}', [F\StructureController::class, 'update'])->name('structures.update');
    });

    // Students: plan, dues, collection
    Route::get('students', [F\StudentFeeController::class, 'index'])->name('students.index');
    Route::get('students/{student}', [F\StudentFeeController::class, 'show'])->name('students.show');
    Route::post('students/{student}/plan', [F\StudentFeeController::class, 'assign'])->middleware('can:academic.fees.configure')->name('students.assign');
    Route::post('students/{student}/payments', [F\StudentFeeController::class, 'collect'])->middleware('can:academic.fees.collect')->name('students.collect');
    Route::post('plans/{feePlan}/cancel', [F\StudentFeeController::class, 'cancelPlan'])->middleware('can:academic.fees.approve')->name('plans.cancel');
    Route::post('plan-concessions/{planConcession}/decide', [F\StudentFeeController::class, 'decideConcession'])->middleware('can:academic.fees.approve')->name('concessions.decide');

    // Invoices
    Route::get('invoices', [F\InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{feeInvoice}', [F\InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('invoices/{feeInvoice}/waive', [F\InvoiceController::class, 'waive'])->middleware('can:academic.fees.approve')->name('invoices.waive');
    Route::post('invoices/{feeInvoice}/cancel', [F\InvoiceController::class, 'cancel'])->middleware('can:academic.fees.approve')->name('invoices.cancel');

    // Receipts and refunds
    Route::get('payments', [F\PaymentController::class, 'index'])->name('payments.index');
    Route::post('settlements', [F\PaymentController::class, 'settlement'])->middleware('can:academic.fees.approve')->name('settlements.store');
    Route::get('payments/{feePayment}', [F\PaymentController::class, 'show'])->name('payments.show');
    Route::post('payments/{feePayment}/bounce', [F\PaymentController::class, 'bounce'])->middleware('can:academic.fees.approve')->name('payments.bounce');
    Route::post('payments/{feePayment}/refunds', [F\PaymentController::class, 'requestRefund'])->middleware('can:academic.fees.collect')->name('payments.refund');
    Route::get('approvals', [F\PaymentController::class, 'approvals'])->name('approvals');
    Route::post('refunds/{feeRefund}/approve', [F\PaymentController::class, 'approveRefund'])->middleware('can:academic.fees.approve')->name('refunds.approve');
    Route::post('refunds/{feeRefund}/reject', [F\PaymentController::class, 'rejectRefund'])->middleware('can:academic.fees.approve')->name('refunds.reject');
    Route::post('refunds/{feeRefund}/process', [F\PaymentController::class, 'processRefund'])->middleware('can:academic.fees.approve')->name('refunds.process');

    // Reports
    Route::get('reports/outstanding', [F\ReportController::class, 'outstanding'])->name('reports.outstanding');
    Route::get('reports/collections', [F\ReportController::class, 'collections'])->name('reports.collections');
    Route::get('reports/concessions', [F\ReportController::class, 'concessions'])->name('reports.concessions');
});
