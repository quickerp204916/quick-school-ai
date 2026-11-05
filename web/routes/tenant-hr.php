<?php

use Illuminate\Support\Facades\Route;
use Modules\Hr\Http\Controllers as H;

/*
| HR and payroll (signed-in staff). Included from routes/tenant.php.
| "me" is the employee self-service portal: every staff member sees only their own records.
*/
Route::prefix('me')->name('me.')->group(function () {
    Route::get('/', [H\SelfServiceController::class, 'index'])->name('index');
    Route::post('leave', [H\SelfServiceController::class, 'applyLeave'])->name('leave');
    Route::post('leave/{leaveRequest}/cancel', [H\SelfServiceController::class, 'cancelLeave'])->name('leave.cancel');
    Route::get('payslips/{payslip}', [H\SelfServiceController::class, 'payslip'])->name('payslip');
    Route::get('documents/{employeeDocument}', [H\SelfServiceController::class, 'document'])->name('document');
});

Route::prefix('hr')->name('hr.')->middleware('can:academic.hr.view')->group(function () {
    Route::get('/', [H\HrController::class, 'dashboard'])->name('dashboard');
    Route::get('employees', [H\HrController::class, 'index'])->name('employees.index');
    Route::get('employees/create', [H\HrController::class, 'create'])->middleware('can:academic.hr.manage')->name('employees.create');
    Route::post('employees', [H\HrController::class, 'store'])->middleware('can:academic.hr.manage')->name('employees.store');
    Route::get('employees/{staff}', [H\HrController::class, 'show'])->name('employees.show');
    Route::put('employees/{staff}', [H\HrController::class, 'update'])->middleware('can:academic.hr.manage')->name('employees.update');
    Route::post('employees/{staff}/salary', [H\HrController::class, 'revise'])->middleware('can:academic.payroll.configure')->name('employees.salary');
    Route::post('employees/{staff}/inputs', [H\HrController::class, 'input'])->middleware('can:academic.payroll.process')->name('employees.inputs');
    Route::post('employees/{staff}/relieve', [H\HrController::class, 'relieve'])->middleware('can:academic.hr.manage')->name('employees.relieve');
    Route::post('employees/{staff}/documents', [H\HrController::class, 'upload'])->middleware('can:academic.hr.manage')->name('documents.store');
    Route::get('documents/{employeeDocument}', [H\HrController::class, 'document'])->name('documents.show');
    Route::delete('documents/{employeeDocument}', [H\HrController::class, 'deleteDocument'])->middleware('can:academic.hr.manage')->name('documents.destroy');

    Route::get('attendance', [H\AttendanceController::class, 'index'])->name('attendance');
    Route::post('attendance', [H\AttendanceController::class, 'mark'])->middleware('can:academic.hr.manage')->name('attendance.mark');
    Route::post('attendance/import', [H\AttendanceController::class, 'import'])->middleware('can:academic.hr.manage')->name('attendance.import');
    Route::get('attendance/month', [H\AttendanceController::class, 'month'])->name('attendance.month');

    Route::get('leave', [H\LeaveController::class, 'index'])->name('leave');
    Route::post('leave', [H\LeaveController::class, 'store'])->middleware('can:academic.hr.manage')->name('leave.store');
    Route::post('leave/{leaveRequest}/decide', [H\LeaveController::class, 'decide'])->middleware('can:academic.hr.manage')->name('leave.decide');
    Route::post('leave/{leaveRequest}/cancel', [H\LeaveController::class, 'cancel'])->middleware('can:academic.hr.manage')->name('leave.cancel');

    Route::get('payroll', [H\PayrollController::class, 'index'])->name('payroll');
    Route::post('payroll', [H\PayrollController::class, 'process'])->middleware('can:academic.payroll.process')->name('payroll.process');
    Route::get('payroll/{payrollRun}', [H\PayrollController::class, 'show'])->name('payroll.show');
    Route::post('payroll/{payrollRun}/approve', [H\PayrollController::class, 'approve'])->middleware('can:academic.payroll.approve')->name('payroll.approve');
    Route::post('payroll/{payrollRun}/reopen', [H\PayrollController::class, 'reopen'])->middleware('can:academic.payroll.process')->name('payroll.reopen');
    Route::get('payroll/{payrollRun}/bank-file', [H\PayrollController::class, 'bankFile'])->middleware('can:academic.payroll.approve')->name('payroll.bank');
    Route::post('payroll/{payrollRun}/paid', [H\PayrollController::class, 'paid'])->middleware('can:academic.payroll.approve')->name('payroll.paid');
    Route::get('payroll/{payrollRun}/reports/{report}', [H\PayrollController::class, 'report'])->whereIn('report', ['pf', 'esi', 'pt', 'tds'])->name('payroll.report');
    Route::get('payslips/{payslip}', [H\PayrollController::class, 'payslip'])->name('payslips.show');

    Route::get('fnf', [H\PayrollController::class, 'fnfIndex'])->name('fnf');
    Route::post('employees/{staff}/fnf', [H\PayrollController::class, 'fnfPrepare'])->middleware('can:academic.payroll.process')->name('fnf.prepare');
    Route::get('fnf/{fnf}', [H\PayrollController::class, 'fnfShow'])->name('fnf.show');
    Route::post('fnf/{fnf}/approve', [H\PayrollController::class, 'fnfApprove'])->middleware('can:academic.payroll.approve')->name('fnf.approve');
    Route::post('fnf/{fnf}/paid', [H\PayrollController::class, 'fnfPaid'])->middleware('can:academic.payroll.approve')->name('fnf.paid');

    Route::get('settings', [H\HrSettingsController::class, 'index'])->middleware('can:academic.payroll.configure')->name('settings');
    Route::post('settings/rules', [H\HrSettingsController::class, 'addRule'])->middleware('can:academic.payroll.configure')->name('settings.rules');
    Route::put('settings/shifts/{shift}', [H\HrSettingsController::class, 'updateShift'])->middleware('can:academic.hr.manage')->name('settings.shift');
});
