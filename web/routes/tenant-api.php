<?php

use Illuminate\Support\Facades\Route;
use Modules\Academic\Http\Controllers\Api\AcademicApiController as A;
use Modules\Academic\Http\Controllers\Api\AuthApiController;

/*
| Academic API v1 for the Flutter app: https://{code}.quickschool.app/api/v1/...
| Sign in with POST auth/token, then send "Authorization: Bearer <token>".
*/
// Payment gateway webhooks: no token; every call is checked against the institution's signing secret.
Route::post('webhooks/fees/{gateway}', [\Modules\Fees\Http\Controllers\Api\WebhookController::class, 'handle'])->whereIn('gateway', ['razorpay', 'cashfree'])
    ->middleware('throttle:120,1')->name('fees.webhook');

// Tool calls from the AI service: no bearer token; signed with the shared key and bound to a live chat session.
Route::post('ai/tools/{tool}', \Modules\Ai\Http\Controllers\Api\ToolController::class)->where('tool', '[a-z_]+\.[a-z_]+')
    ->middleware('throttle:600,1')->name('ai.tool');

Route::post('auth/token', [AuthApiController::class, 'token'])->middleware('throttle:tenant-login')->name('auth.token');

Route::middleware(['auth:sanctum', 'tenant.member', 'throttle:tenant-api'])->group(function () {
    Route::delete('auth/token', [AuthApiController::class, 'revoke'])->name('auth.revoke');
    Route::get('me', [AuthApiController::class, 'me'])->name('me');

    Route::get('sections', [A::class, 'sections'])->name('sections');
    Route::get('sections/{section}/students', [A::class, 'sectionStudents'])->name('sections.students');
    Route::get('sections/{section}/timetable', [A::class, 'sectionTimetable'])->name('sections.timetable');
    Route::get('timetable/me', [A::class, 'myTimetable'])->name('timetable.me');

    Route::get('students', [A::class, 'students'])->name('students');
    Route::post('students', [A::class, 'storeStudent'])->name('students.store');
    Route::get('students/{student}', [A::class, 'student'])->name('students.show');
    Route::patch('students/{student}', [A::class, 'updateStudent'])->name('students.update');
    Route::get('students/{student}/attendance', [A::class, 'studentAttendance'])->name('students.attendance');
    Route::get('students/{student}/report-cards', [A::class, 'studentReportCards'])->name('students.report-cards');
    Route::get('students/{student}/certificates', [A::class, 'studentCertificates'])->name('students.certificates');

    Route::get('admissions', [A::class, 'admissions'])->name('admissions');
    Route::post('admissions', [A::class, 'storeAdmission'])->name('admissions.store');
    Route::post('admissions/{admission}/stage', [A::class, 'admissionStage'])->name('admissions.stage');
    Route::get('admissions/{admission}/documents', [A::class, 'admissionDocuments'])->name('admissions.documents');
    Route::post('admissions/{admission}/documents', [A::class, 'uploadAdmissionDocument'])->name('admissions.documents.store');
    Route::get('students/{student}/documents', [A::class, 'studentDocuments'])->name('students.documents');
    Route::post('students/{student}/documents', [A::class, 'uploadStudentDocument'])->name('students.documents.store');
    Route::get('students/{student}/photo', [A::class, 'studentPhoto'])->name('students.photo');
    Route::get('documents/{document}', [A::class, 'document'])->name('documents.show');

    Route::get('attendance', [A::class, 'attendanceSheet'])->name('attendance.sheet');
    Route::post('attendance', [A::class, 'markAttendance'])->name('attendance.mark');

    Route::get('exams/papers', [A::class, 'exams'])->name('exams.papers');
    Route::get('papers/{paper}/marks', [A::class, 'paperMarks'])->name('papers.marks');
    Route::post('papers/{paper}/marks', [A::class, 'saveMarks'])->name('papers.marks.save');

    Route::get('homework', [A::class, 'homework'])->name('homework');
    Route::post('homework', [A::class, 'storeHomework'])->name('homework.store');
    Route::post('homework/{homework}/submit', [A::class, 'submitHomework'])->name('homework.submit');

    // Accounting
    Route::prefix('accounting')->name('accounting.')->group(function () {
        $C = \Modules\Accounting\Http\Controllers\Api\AccountingApiController::class;
        Route::get('ledgers', [$C, 'ledgers'])->name('ledgers');
        Route::get('vouchers', [$C, 'vouchers'])->name('vouchers');
        Route::post('vouchers', [$C, 'store'])->name('vouchers.store');
        Route::get('vouchers/{voucher}', [$C, 'voucherShow'])->name('vouchers.show');
        Route::post('vouchers/{voucher}/approve', [$C, 'approve'])->name('vouchers.approve');
        Route::post('vouchers/{voucher}/reject', [$C, 'reject'])->name('vouchers.reject');
        Route::post('vouchers/{voucher}/reverse', [$C, 'reverse'])->name('vouchers.reverse');
        Route::post('invoices', [$C, 'storeInvoice'])->name('invoices.store');
        Route::get('reports/{report}', [$C, 'report'])->whereIn('report', ['trial-balance', 'profit-loss', 'balance-sheet', 'cash-flow'])->name('reports');
    });

    // Fees: parents see and pay their children's fees; staff collect at the counter.
    Route::prefix('fees')->name('fees.')->group(function () {
        $F = \Modules\Fees\Http\Controllers\Api\FeesApiController::class;
        Route::get('students/{student}', [$F, 'student'])->name('student');
        Route::post('students/{student}/payments', [$F, 'collect'])->name('collect');
        Route::post('orders', [$F, 'createOrder'])->name('orders.store');
        Route::post('orders/{feeOrder}/confirm', [$F, 'confirm'])->name('orders.confirm');
        Route::get('payments/{feePayment}', [$F, 'payment'])->name('payments.show');
        Route::get('outstanding', [$F, 'outstanding'])->name('outstanding');
    });

    // HR self-service and leave approval
    Route::prefix('hr')->name('hr.')->group(function () {
        $H = \Modules\Hr\Http\Controllers\Api\HrApiController::class;
        Route::get('me', [$H, 'me'])->name('me');
        Route::get('me/payslips', [$H, 'payslips'])->name('payslips');
        Route::get('me/payslips/{payslip}', [$H, 'payslip'])->name('payslip');
        Route::get('me/attendance', [$H, 'attendance'])->name('attendance');
        Route::post('me/leave', [$H, 'applyLeave'])->name('leave.apply');
        Route::get('leave/pending', [$H, 'pendingLeave'])->name('leave.pending');
        Route::post('leave/{leaveRequest}/decide', [$H, 'decideLeave'])->name('leave.decide');
    });

    // AI assistants
    Route::prefix('ai')->name('ai.')->group(function () {
        $I = \Modules\Ai\Http\Controllers\Api\AiApiController::class;
        Route::get('agents', [$I, 'agents'])->name('agents');
        Route::get('conversations', [$I, 'conversations'])->name('conversations');
        Route::get('conversations/{aiConversation}', [$I, 'conversation'])->name('conversations.show');
        Route::post('chat', [$I, 'send'])->middleware('throttle:30,1')->name('chat');
        Route::get('approvals', [$I, 'approvals'])->name('approvals');
        Route::post('approvals/{aiRequest}/approve', [$I, 'approve'])->name('approvals.approve');
        Route::post('approvals/{aiRequest}/reject', [$I, 'reject'])->name('approvals.reject');
    });
});
